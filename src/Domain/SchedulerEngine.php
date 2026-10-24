<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Động cơ xếp thời khóa biểu (SchedulerEngine) - Tự hiện thực 100% bởi nhóm.
 *
 * Cung cấp:
 * 1. Cách xếp cơ bản (BASIC - Sequential First-Fit Greedy)
 * 2. Cách xếp cải thiện (IMPROVED - Dynamic MRV + Best-Fit Lookahead + Augmenting Swap Repair + Local Search)
 * 3. Kiểm tra ảnh hưởng khi đổi lịch thủ công (Manual Move / Swap Impact Analysis)
 * 4. So sánh hai phương án trên cùng dữ liệu thực tế (Version Comparison)
 */
class SchedulerEngine
{
    private ConstraintChecker $checker;

    public function __construct(?ConstraintChecker $checker = null)
    {
        $this->checker = $checker ?? new ConstraintChecker();
    }

    public function getChecker(): ConstraintChecker
    {
        return $this->checker;
    }

    /**
     * Tạo mã băm (SHA-256) đại diện cho bộ dữ liệu và ràng buộc đầu vào.
     * Dùng để chứng minh hai phương án được chạy và so sánh trên cùng dữ liệu & điều kiện.
     */
    public function computeDatasetHash(array $dataset): string
    {
        $normalized = [
            'teachers' => $dataset['teachers'] ?? [],
            'classes' => $dataset['classes'] ?? [],
            'rooms' => $dataset['rooms'] ?? [],
            'slots' => $dataset['slots'] ?? [],
            'lessons' => $dataset['lessons'] ?? [],
            'unavailability' => $dataset['unavailability'] ?? [],
        ];
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * CÁCH XẾP 1: CƠ BẢN (Basic Sequential First-Fit)
     * - Bước 1: Giữ nguyên tuyệt đối các buổi học đã khóa (is_locked = 1).
     * - Bước 2: Duyệt tuần tự các buổi chưa khóa theo thứ tự danh sách (id tăng dần).
     * - Bước 3: Duyệt tuần tự từng khung giờ (slots) và từng phòng học (rooms),
     *           chọn cặp (slot, room) đầu tiên thỏa mãn toàn bộ điều kiện bắt buộc (First-Fit).
     * - Bước 4: Nếu chưa tìm thấy vị trí cho buổi nào, ghi nhận vào danh sách chưa xếp kèm nguyên nhân,
     *           không kết luận bài toán vô nghiệm.
     */
    public function scheduleBasic(array $dataset): array
    {
        $validation = $this->checker->validateDataset($dataset);
        if (!$validation['is_ready_to_schedule']) {
            return [
                'success' => false,
                'algorithm_type' => 'BASIC',
                'status_code' => 'INVALID_INPUT_DATA',
                'status_message' => 'Dữ liệu đầu vào hoặc buổi học đã khóa có vi phạm điều kiện bắt buộc. Vui lòng sửa trước khi xếp lịch.',
                'validation' => $validation,
            ];
        }

        $lessons = $dataset['lessons'] ?? [];
        $slots = $dataset['slots'] ?? [];
        $rooms = $dataset['rooms'] ?? [];

        // Sắp xếp tuần tự theo ID tăng dần
        usort($lessons, fn(array $a, array $b) => ((int)$a['id']) <=> ((int)$b['id']));
        usort($slots, fn(array $a, array $b) => ((int)$a['id']) <=> ((int)$b['id']));
        usort($rooms, fn(array $a, array $b) => ((int)$a['id']) <=> ((int)$b['id']));

        $assignments = [];

        // Bước 1: Cố định toàn bộ các buổi đã khóa (is_locked = 1)
        foreach ($lessons as $lesson) {
            if ((int)$lesson['is_locked'] === 1) {
                $lId = (int)$lesson['id'];
                $assignments[$lId] = [
                    'lesson_id' => $lId,
                    'slot_id' => (int)$lesson['locked_slot_id'],
                    'room_id' => (int)$lesson['locked_room_id'],
                    'is_locked' => 1,
                ];
            }
        }

        // Bước 2: Duyệt tuần tự First-Fit cho các buổi chưa khóa
        foreach ($lessons as $lesson) {
            $lId = (int)$lesson['id'];
            if (isset($assignments[$lId])) {
                continue; // Đã khóa
            }

            foreach ($slots as $slot) {
                $placed = false;
                foreach ($rooms as $room) {
                    $hardCheck = $this->checker->checkHardConstraintsForPlacement(
                        $lesson,
                        $slot,
                        $room,
                        $assignments,
                        $dataset,
                        $lId
                    );
                    if ($hardCheck['valid']) {
                        $assignments[$lId] = [
                            'lesson_id' => $lId,
                            'slot_id' => (int)$slot['id'],
                            'room_id' => (int)$room['id'],
                            'is_locked' => 0,
                        ];
                        $placed = true;
                        break;
                    }
                }
                if ($placed) {
                    break;
                }
            }
        }

        return $this->buildScheduleResult('BASIC', $assignments, $dataset, $validation);
    }

    /**
     * CÁCH XẾP 2: CẢI THIỆN (Improved Heuristic: Dynamic MRV + Best-Fit Lookahead + Swap Repair + Local Search)
     * - Bước 1: Giữ nguyên tuyệt đối các buổi đã khóa (is_locked = 1).
     * - Bước 2 (Dynamic MRV & Degree Heuristic): Ở mỗi bước, tính miền giá trị hợp lệ (Domain)
     *           cho mọi buổi chưa xếp; chọn buổi có ít phương án hợp lệ nhất (MRV) và độ khó cao nhất.
     * - Bước 3 (Best-Fit Room & Look-ahead Soft Score): Chọn cặp (slot, room) vừa khít sĩ số lớp,
     *           ít gây cản trở các buổi chưa xếp khác nhất, và có điểm phạt ưu tiên (soft penalty) thấp nhất.
     * - Bước 4 (Augmenting Swap Repair): Với các buổi còn kẹt, tự động tìm buổi không khóa để dịch chuyển
     *           nhường chỗ nếu cả 2 buổi cùng thỏa mãn 100% điều kiện bắt buộc.
     * - Bước 5 (Local Search Optimization): Tối ưu hóa cục bộ để giảm thêm điểm phạt vi phạm ưu tiên
     *           mà không chạm vào bất kỳ buổi đã khóa nào.
     */
    public function scheduleImproved(array $dataset): array
    {
        $validation = $this->checker->validateDataset($dataset);
        if (!$validation['is_ready_to_schedule']) {
            return [
                'success' => false,
                'algorithm_type' => 'IMPROVED',
                'status_code' => 'INVALID_INPUT_DATA',
                'status_message' => 'Dữ liệu đầu vào hoặc buổi học đã khóa có vi phạm điều kiện bắt buộc. Vui lòng sửa trước khi xếp lịch.',
                'validation' => $validation,
            ];
        }

        $lessons = $dataset['lessons'] ?? [];
        $slots = $dataset['slots'] ?? [];
        $rooms = $dataset['rooms'] ?? [];

        $lessonsById = [];
        foreach ($lessons as $l) {
            $lessonsById[(int)$l['id']] = $l;
        }
        $slotsById = [];
        foreach ($slots as $s) {
            $slotsById[(int)$s['id']] = $s;
        }
        $roomsById = [];
        foreach ($rooms as $r) {
            $roomsById[(int)$r['id']] = $r;
        }

        $assignments = [];
        $unassignedIds = [];

        // Bước 1: Cố định toàn bộ các buổi đã khóa (is_locked = 1)
        foreach ($lessonsById as $lId => $lesson) {
            if ((int)$lesson['is_locked'] === 1) {
                $assignments[$lId] = [
                    'lesson_id' => $lId,
                    'slot_id' => (int)$lesson['locked_slot_id'],
                    'room_id' => (int)$lesson['locked_room_id'],
                    'is_locked' => 1,
                ];
            } else {
                $unassignedIds[$lId] = $lId;
            }
        }

        // Bước 2 & 3: Dynamic MRV + Degree Heuristic + Best-Fit Lookahead
        $stuckIds = [];
        while (!empty($unassignedIds)) {
            $domains = [];
            foreach ($unassignedIds as $lId) {
                $lesson = $lessonsById[$lId];
                $validPairs = [];
                foreach ($slotsById as $sId => $slot) {
                    foreach ($roomsById as $rId => $room) {
                        $hardCheck = $this->checker->checkHardConstraintsForPlacement(
                            $lesson,
                            $slot,
                            $room,
                            $assignments,
                            $dataset,
                            $lId
                        );
                        if ($hardCheck['valid']) {
                            $validPairs[] = ['slot_id' => $sId, 'room_id' => $rId];
                        }
                    }
                }
                $domains[$lId] = $validPairs;
            }

            // Tách các buổi đã hết phương án ở nhánh hiện tại sang tập cần giải cứu (stuckIds)
            $selectableIds = [];
            foreach ($unassignedIds as $lId) {
                if (empty($domains[$lId])) {
                    $stuckIds[$lId] = $lId;
                    unset($unassignedIds[$lId]);
                } else {
                    $selectableIds[] = $lId;
                }
            }

            if (empty($selectableIds)) {
                break;
            }

            // Chọn buổi học tiếp theo theo MRV (số phương án hợp lệ nhỏ nhất),
            // nếu bằng nhau ưu tiên buổi có sĩ số lớn hơn (khó chọn phòng hơn)
            usort($selectableIds, function (int $aId, int $bId) use ($domains, $lessonsById): int {
                $countA = count($domains[$aId]);
                $countB = count($domains[$bId]);
                if ($countA !== $countB) {
                    return $countA <=> $countB;
                }
                $sizeA = (int)$lessonsById[$aId]['student_count'];
                $sizeB = (int)$lessonsById[$bId]['student_count'];
                if ($sizeA !== $sizeB) {
                    return $sizeB <=> $sizeA; // Sĩ số lớn hơn xếp trước
                }
                return $aId <=> $bId;
            });

            $chosenLessonId = $selectableIds[0];
            $chosenLesson = $lessonsById[$chosenLessonId];
            $candidatePairs = $domains[$chosenLessonId];

            // Chấm điểm từng cặp (slot, room) trong miền hợp lệ của buổi được chọn
            $bestPair = null;
            $bestScore = PHP_INT_MAX;

            foreach ($candidatePairs as $pair) {
                $slot = $slotsById[$pair['slot_id']];
                $room = $roomsById[$pair['room_id']];

                $softEval = $this->checker->evaluateSoftConstraintsForPlacement(
                    $chosenLesson,
                    $slot,
                    $room,
                    $assignments,
                    $dataset,
                    $chosenLessonId
                );

                // Tính độ cản trở (Look-ahead conflict impact) đối với các buổi chưa xếp khác
                $lookaheadImpact = 0;
                foreach ($unassignedIds as $otherId) {
                    if ($otherId === $chosenLessonId) {
                        continue;
                    }
                    foreach ($domains[$otherId] as $otherPair) {
                        if ($otherPair['slot_id'] === $pair['slot_id']) {
                            $otherLesson = $lessonsById[$otherId];
                            if ($otherPair['room_id'] === $pair['room_id']
                                || (int)$otherLesson['teacher_id'] === (int)$chosenLesson['teacher_id']
                                || (int)$otherLesson['class_id'] === (int)$chosenLesson['class_id']
                            ) {
                                // Nếu buổi khác có miền rất hẹp thì việc lấy mất slot/room của nó bị phạt rất nặng
                                $weight = count($domains[$otherId]) <= 3 ? 40 : 8;
                                $lookaheadImpact += $weight;
                            }
                        }
                    }
                }

                // Hàm chi phí tổng hợp: ưu tiên không làm kẹt buổi khác + giảm phạt mềm + chọn phòng vừa sức chứa
                $seatSlack = (int)$room['capacity'] - (int)$chosenLesson['student_count'];
                $score = ($softEval['penalty'] * 100) + ($lookaheadImpact * 10) + ($seatSlack * 2) + (int)$slot['id'];

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestPair = $pair;
                }
            }

            if ($bestPair !== null) {
                $assignments[$chosenLessonId] = [
                    'lesson_id' => $chosenLessonId,
                    'slot_id' => $bestPair['slot_id'],
                    'room_id' => $bestPair['room_id'],
                    'is_locked' => 0,
                ];
            }
            unset($unassignedIds[$chosenLessonId]);
        }

        // Bước 4: Pha hoán đổi giải cứu (Single-Step Augmenting Swap Repair) cho các buổi bị kẹt
        foreach ($stuckIds as $stuckLessonId) {
            $stuckLesson = $lessonsById[$stuckLessonId];
            $repaired = false;

            foreach ($assignments as $assignedLessonId => $currentPlacement) {
                if ((int)$currentPlacement['is_locked'] === 1) {
                    continue; // Tuyệt đối không động vào buổi đã khóa
                }

                $candidateSlot = $slotsById[(int)$currentPlacement['slot_id']];
                $candidateRoom = $roomsById[(int)$currentPlacement['room_id']];

                // Thử tạm tháo buổi $assignedLessonId ra xem $stuckLesson có vào được vị trí đó không
                $tempAssignments = $assignments;
                unset($tempAssignments[$assignedLessonId]);

                $canStuckTakeSpot = $this->checker->checkHardConstraintsForPlacement(
                    $stuckLesson,
                    $candidateSlot,
                    $candidateRoom,
                    $tempAssignments,
                    $dataset,
                    $stuckLessonId
                );

                if (!$canStuckTakeSpot['valid']) {
                    continue;
                }

                // Tạm đặt $stuckLesson vào vị trí đó và tìm vị trí mới hợp lệ cho $assignedLessonId
                $tempAssignments[$stuckLessonId] = [
                    'lesson_id' => $stuckLessonId,
                    'slot_id' => (int)$candidateSlot['id'],
                    'room_id' => (int)$candidateRoom['id'],
                    'is_locked' => 0,
                ];

                $displacedLesson = $lessonsById[$assignedLessonId];
                foreach ($slotsById as $newSlotId => $newSlot) {
                    foreach ($roomsById as $newRoomId => $newRoom) {
                        $canDisplacedMove = $this->checker->checkHardConstraintsForPlacement(
                            $displacedLesson,
                            $newSlot,
                            $newRoom,
                            $tempAssignments,
                            $dataset,
                            $assignedLessonId
                        );
                        if ($canDisplacedMove['valid']) {
                            $tempAssignments[$assignedLessonId] = [
                                'lesson_id' => $assignedLessonId,
                                'slot_id' => $newSlotId,
                                'room_id' => $newRoomId,
                                'is_locked' => 0,
                            ];
                            $assignments = $tempAssignments;
                            $repaired = true;
                            break 2;
                        }
                    }
                }

                if ($repaired) {
                    break;
                }
            }
        }

        // Bước 5: Tối ưu hóa cục bộ (Local Search Relocate) giảm điểm phạt ưu tiên cho các buổi không khóa
        $improved = true;
        $iteration = 0;
        while ($improved && $iteration < 3) {
            $improved = false;
            $iteration++;

            foreach ($assignments as $lId => $placement) {
                if ((int)$placement['is_locked'] === 1) {
                    continue; // Không bao giờ đổi buổi đã khóa
                }

                $lesson = $lessonsById[$lId];
                $currentSlot = $slotsById[(int)$placement['slot_id']];
                $currentRoom = $roomsById[(int)$placement['room_id']];

                $currentSoft = $this->checker->evaluateSoftConstraintsForPlacement(
                    $lesson,
                    $currentSlot,
                    $currentRoom,
                    $assignments,
                    $dataset,
                    $lId
                );

                if ($currentSoft['penalty'] === 0) {
                    continue;
                }

                $bestSlotId = (int)$placement['slot_id'];
                $bestRoomId = (int)$placement['room_id'];
                $bestPenalty = $currentSoft['penalty'];

                foreach ($slotsById as $sId => $slot) {
                    foreach ($roomsById as $rId => $room) {
                        if ($sId === $bestSlotId && $rId === $bestRoomId) {
                            continue;
                        }
                        $hardCheck = $this->checker->checkHardConstraintsForPlacement(
                            $lesson,
                            $slot,
                            $room,
                            $assignments,
                            $dataset,
                            $lId
                        );
                        if (!$hardCheck['valid']) {
                            continue;
                        }
                        $softCheck = $this->checker->evaluateSoftConstraintsForPlacement(
                            $lesson,
                            $slot,
                            $room,
                            $assignments,
                            $dataset,
                            $lId
                        );
                        if ($softCheck['penalty'] < $bestPenalty) {
                            $bestPenalty = $softCheck['penalty'];
                            $bestSlotId = $sId;
                            $bestRoomId = $rId;
                        }
                    }
                }

                if ($bestPenalty < $currentSoft['penalty']) {
                    $assignments[$lId]['slot_id'] = $bestSlotId;
                    $assignments[$lId]['room_id'] = $bestRoomId;
                    $improved = true;
                }
            }
        }

        return $this->buildScheduleResult('IMPROVED', $assignments, $dataset, $validation);
    }

    /**
     * Đóng gói kết quả xếp lịch kèm kiểm toán thực tế từ ConstraintChecker.
     * Nếu chưa xếp hết 100% số buổi, trả về trạng thái PARTIAL_SCHEDULE kèm danh sách chưa xếp,
     * tuyệt đối không kết luận bài toán vô nghiệm (Yêu cầu kỹ thuật 2).
     */
    private function buildScheduleResult(
        string $algorithmType,
        array $assignments,
        array $dataset,
        array $validation
    ): array {
        $audit = $this->checker->auditScheduleAssignments($assignments, $dataset);

        $enrichedAssignments = [];
        foreach ($assignments as $lId => $a) {
            $auditItem = $audit['assignment_audits'][$lId] ?? [
                'soft_penalty' => 0,
                'soft_notes' => 'Tối ưu',
            ];
            $enrichedAssignments[$lId] = [
                'lesson_id' => (int)$a['lesson_id'],
                'slot_id' => (int)$a['slot_id'],
                'room_id' => (int)$a['room_id'],
                'is_locked' => (int)$a['is_locked'],
                'soft_penalty' => (int)$auditItem['soft_penalty'],
                'soft_notes' => (string)$auditItem['soft_notes'],
            ];
        }

        $isComplete = ($audit['unscheduled_count'] === 0);
        $algoLabel = $algorithmType === 'IMPROVED'
            ? 'Cách xếp cải thiện (MRV + Best-Fit + Swap Repair)'
            : 'Cách xếp cơ bản (Sequential First-Fit)';

        if ($isComplete) {
            $statusCode = 'COMPLETE_SCHEDULE';
            $statusMessage = "{$algoLabel}: Đã xếp thành công toàn bộ {$audit['scheduled_count']}/{$audit['total_lessons']} buổi học (0 vi phạm bắt buộc, điểm phạt ưu tiên: {$audit['soft_penalty_score']}).";
        } else {
            $statusCode = 'PARTIAL_SCHEDULE';
            $statusMessage = "{$algoLabel}: Đã xếp được {$audit['scheduled_count']}/{$audit['total_lessons']} buổi học. Còn {$audit['unscheduled_count']} buổi chưa tìm được vị trí khả thi trong lần chạy này (Hệ thống báo rõ danh sách chưa xếp và nguyên nhân, KHÔNG kết luận bài toán vô nghiệm).";
        }

        return [
            'success' => true,
            'algorithm_type' => $algorithmType,
            'status_code' => $statusCode,
            'status_message' => $statusMessage,
            'total_lessons' => $audit['total_lessons'],
            'scheduled_count' => $audit['scheduled_count'],
            'unscheduled_count' => $audit['unscheduled_count'],
            'hard_violations' => $audit['hard_violations'],
            'soft_penalty_score' => $audit['soft_penalty_score'],
            'assignments' => array_values($enrichedAssignments),
            'unscheduled_lessons' => $audit['unscheduled_lessons'],
            'dataset_hash' => $this->computeDatasetHash($dataset),
            'validation' => $validation,
        ];
    }

    /**
     * CHỨC NĂNG 4: Kiểm tra ảnh hưởng khi người dùng thử đổi lịch thủ công (hoặc xếp thử buổi chưa xếp)
     * vào (targetSlotId, targetRoomId) trên phương án đang xét.
     */
    public function checkManualMoveImpact(
        int $lessonId,
        int $targetSlotId,
        int $targetRoomId,
        array $currentAssignments,
        array $dataset
    ): array {
        $lessonsById = [];
        foreach ($dataset['lessons'] ?? [] as $l) {
            $lessonsById[(int)$l['id']] = $l;
        }
        $slotsById = [];
        foreach ($dataset['slots'] ?? [] as $s) {
            $slotsById[(int)$s['id']] = $s;
        }
        $roomsById = [];
        foreach ($dataset['rooms'] ?? [] as $r) {
            $roomsById[(int)$r['id']] = $r;
        }

        $lesson = $lessonsById[$lessonId] ?? null;
        $targetSlot = $slotsById[$targetSlotId] ?? null;
        $targetRoom = $roomsById[$targetRoomId] ?? null;

        if ($lesson === null || $targetSlot === null || $targetRoom === null) {
            return [
                'can_apply' => false,
                'hard_valid' => false,
                'hard_violations' => [['code' => 'NOT_FOUND', 'message' => 'Buổi học, khung giờ hoặc phòng học không tồn tại.']],
                'soft_penalty_before' => 0,
                'soft_penalty_after' => 0,
                'soft_delta' => 0,
                'summary' => 'Dữ liệu đầu vào không hợp lệ.',
            ];
        }

        $indexedAssignments = [];
        foreach ($currentAssignments as $a) {
            $indexedAssignments[(int)$a['lesson_id']] = $a;
        }

        $auditBefore = $this->checker->auditScheduleAssignments($indexedAssignments, $dataset);

        // Kiểm tra điều kiện bắt buộc tại vị trí mới
        $hardCheck = $this->checker->checkHardConstraintsForPlacement(
            $lesson,
            $targetSlot,
            $targetRoom,
            $indexedAssignments,
            $dataset,
            $lessonId
        );

        $softCheckAfter = $this->checker->evaluateSoftConstraintsForPlacement(
            $lesson,
            $targetSlot,
            $targetRoom,
            $indexedAssignments,
            $dataset,
            $lessonId
        );

        // Mô phỏng phương án sau khi đổi
        $simulatedAssignments = $indexedAssignments;
        $simulatedAssignments[$lessonId] = [
            'lesson_id' => $lessonId,
            'slot_id' => $targetSlotId,
            'room_id' => $targetRoomId,
            'is_locked' => (int)$lesson['is_locked'],
        ];
        $auditAfter = $this->checker->auditScheduleAssignments($simulatedAssignments, $dataset);

        $wasUnscheduled = !isset($indexedAssignments[$lessonId]);
        $deltaSoft = $auditAfter['soft_penalty_score'] - $auditBefore['soft_penalty_score'];

        if (!$hardCheck['valid']) {
            $messages = array_column($hardCheck['violations'], 'message');
            $summary = "KHÔNG THỂ ĐỔI LỊCH: Vi phạm " . count($hardCheck['violations']) . " điều kiện bắt buộc (" . implode(' | ', $messages) . ").";
        } else {
            $actionDesc = $wasUnscheduled
                ? "Xếp bổ sung buổi học [{$lesson['code']}] (tăng số buổi xếp được từ {$auditBefore['scheduled_count']} lên {$auditAfter['scheduled_count']})"
                : "Chuyển buổi học [{$lesson['code']}] sang {$targetSlot['code']} - Phòng {$targetRoom['code']}";
            $deltaText = $deltaSoft > 0 ? "+{$deltaSoft} điểm phạt" : ($deltaSoft < 0 ? "{$deltaSoft} điểm phạt (Tốt hơn)" : "Không đổi điểm phạt");
            $summary = "HỢP LỆ (0 vi phạm bắt buộc): {$actionDesc}. Biến thiên điểm phạt ưu tiên: {$deltaText}.";
        }

        return [
            'can_apply' => $hardCheck['valid'],
            'hard_valid' => $hardCheck['valid'],
            'hard_violations' => $hardCheck['violations'],
            'was_unscheduled' => $wasUnscheduled,
            'scheduled_before' => $auditBefore['scheduled_count'],
            'scheduled_after' => $auditAfter['scheduled_count'],
            'unscheduled_before' => $auditBefore['unscheduled_count'],
            'unscheduled_after' => $auditAfter['unscheduled_count'],
            'soft_penalty_before' => $auditBefore['soft_penalty_score'],
            'soft_penalty_after' => $auditAfter['soft_penalty_score'],
            'soft_delta' => $deltaSoft,
            'lesson_soft_notes' => $softCheckAfter['notes_text'],
            'unscheduled_lessons_after' => $auditAfter['unscheduled_lessons'],
            'summary' => $summary,
        ];
    }

    /**
     * CHỨC NĂNG 5 & YÊU CẦU KỸ THUẬT 2:
     * So sánh 2 phương án trên cùng dữ liệu; tính toán trực tiếp số buổi xếp được,
     * số buổi chưa xếp, số vi phạm bắt buộc và mức vi phạm ưu tiên từ lịch thực tế.
     */
    public function compareTwoVersions(
        array $versionA,
        array $assignmentsA,
        array $versionB,
        array $assignmentsB,
        array $dataset
    ): array {
        $auditA = $this->checker->auditScheduleAssignments($assignmentsA, $dataset);
        $auditB = $this->checker->auditScheduleAssignments($assignmentsB, $dataset);

        $mapA = [];
        foreach ($assignmentsA as $a) {
            $mapA[(int)$a['lesson_id']] = $a;
        }
        $mapB = [];
        foreach ($assignmentsB as $b) {
            $mapB[(int)$b['lesson_id']] = $b;
        }

        $slotsById = [];
        foreach ($dataset['slots'] ?? [] as $s) {
            $slotsById[(int)$s['id']] = $s;
        }
        $roomsById = [];
        foreach ($dataset['rooms'] ?? [] as $r) {
            $roomsById[(int)$r['id']] = $r;
        }
        $teachersById = [];
        foreach ($dataset['teachers'] ?? [] as $t) {
            $teachersById[(int)$t['id']] = $t;
        }
        $classesById = [];
        foreach ($dataset['classes'] ?? [] as $c) {
            $classesById[(int)$c['id']] = $c;
        }

        $lessonRows = [];
        $lockedPreservedAll = true;
        $samePlacementCount = 0;
        $diffPlacementCount = 0;

        foreach ($dataset['lessons'] ?? [] as $lesson) {
            $lId = (int)$lesson['id'];
            $isLocked = (int)$lesson['is_locked'] === 1;
            $a = $mapA[$lId] ?? null;
            $b = $mapB[$lId] ?? null;

            $aText = $a !== null
                ? (($slotsById[(int)$a['slot_id']]['code'] ?? '?') . ' @ ' . ($roomsById[(int)$a['room_id']]['code'] ?? '?'))
                : 'CHƯA XẾP ĐƯỢC';
            $bText = $b !== null
                ? (($slotsById[(int)$b['slot_id']]['code'] ?? '?') . ' @ ' . ($roomsById[(int)$b['room_id']]['code'] ?? '?'))
                : 'CHƯA XẾP ĐƯỢC';

            $aSoft = $a !== null ? (int)($auditA['assignment_audits'][$lId]['soft_penalty'] ?? 0) : null;
            $bSoft = $b !== null ? (int)($auditB['assignment_audits'][$lId]['soft_penalty'] ?? 0) : null;

            $isIdentical = ($a !== null && $b !== null
                && (int)$a['slot_id'] === (int)$b['slot_id']
                && (int)$a['room_id'] === (int)$b['room_id']);

            if ($isIdentical) {
                $samePlacementCount++;
            } else {
                $diffPlacementCount++;
            }

            if ($isLocked) {
                $lockedSlot = (int)$lesson['locked_slot_id'];
                $lockedRoom = (int)$lesson['locked_room_id'];
                if ($a === null || (int)$a['slot_id'] !== $lockedSlot || (int)$a['room_id'] !== $lockedRoom
                    || $b === null || (int)$b['slot_id'] !== $lockedSlot || (int)$b['room_id'] !== $lockedRoom
                ) {
                    $lockedPreservedAll = false;
                }
            }

            $statusBadge = 'SAME';
            if ($a === null && $b !== null) {
                $statusBadge = 'GAINED_IN_B';
            } elseif ($a !== null && $b === null) {
                $statusBadge = 'LOST_IN_B';
            } elseif (!$isIdentical) {
                $statusBadge = 'CHANGED';
            }

            $lessonRows[] = [
                'lesson_id' => $lId,
                'code' => $lesson['code'],
                'subject_name' => $lesson['subject_name'],
                'teacher_name' => $teachersById[(int)$lesson['teacher_id']]['name'] ?? '',
                'class_code' => $classesById[(int)$lesson['class_id']]['code'] ?? '',
                'student_count' => (int)$lesson['student_count'],
                'required_room_type' => $lesson['required_room_type'],
                'is_locked' => $isLocked,
                'version_a_placement' => $aText,
                'version_b_placement' => $bText,
                'version_a_soft' => $aSoft,
                'version_b_soft' => $bSoft,
                'status_badge' => $statusBadge,
            ];
        }

        $sameDataset = ($versionA['dataset_hash'] ?? '') === ($versionB['dataset_hash'] ?? '');

        // Kết luận phương án tốt hơn dựa trên: (1) Số vi phạm bắt buộc = 0, (2) Số buổi xếp được nhiều hơn, (3) Điểm phạt ưu tiên thấp hơn
        $winner = 'TIE';
        $recommendation = 'Hai phương án có chất lượng tương đương.';
        if ($auditB['hard_violations'] === 0 && $auditA['hard_violations'] === 0) {
            if ($auditB['scheduled_count'] > $auditA['scheduled_count']) {
                $winner = 'B';
                $diff = $auditB['scheduled_count'] - $auditA['scheduled_count'];
                $recommendation = "Phương án B ({$versionB['version_name']}) vượt trội hơn: xếp thêm được +{$diff} buổi học ({$auditB['scheduled_count']}/{$auditB['total_lessons']} so với {$auditA['scheduled_count']}/{$auditA['total_lessons']}) và điểm phạt ưu tiên là {$auditB['soft_penalty_score']} (so với {$auditA['soft_penalty_score']}).";
            } elseif ($auditA['scheduled_count'] > $auditB['scheduled_count']) {
                $winner = 'A';
                $diff = $auditA['scheduled_count'] - $auditB['scheduled_count'];
                $recommendation = "Phương án A ({$versionA['version_name']}) vượt trội hơn: xếp thêm được +{$diff} buổi học ({$auditA['scheduled_count']}/{$auditA['total_lessons']} so với {$auditB['scheduled_count']}/{$auditB['total_lessons']}).";
            } elseif ($auditB['soft_penalty_score'] < $auditA['soft_penalty_score']) {
                $winner = 'B';
                $saved = $auditA['soft_penalty_score'] - $auditB['soft_penalty_score'];
                $recommendation = "Cùng xếp được {$auditB['scheduled_count']}/{$auditB['total_lessons']} buổi nhưng Phương án B ({$versionB['version_name']}) tối ưu hơn: giảm được {$saved} điểm phạt vi phạm ưu tiên ({$auditB['soft_penalty_score']}đ so với {$auditA['soft_penalty_score']}đ).";
            } elseif ($auditA['soft_penalty_score'] < $auditB['soft_penalty_score']) {
                $winner = 'A';
                $saved = $auditB['soft_penalty_score'] - $auditA['soft_penalty_score'];
                $recommendation = "Cùng xếp được {$auditA['scheduled_count']}/{$auditA['total_lessons']} buổi nhưng Phương án A ({$versionA['version_name']}) tối ưu hơn: giảm được {$saved} điểm phạt vi phạm ưu tiên.";
            }
        }

        return [
            'same_dataset' => $sameDataset,
            'locked_preserved_all' => $lockedPreservedAll,
            'winner' => $winner,
            'recommendation' => $recommendation,
            'same_placement_count' => $samePlacementCount,
            'diff_placement_count' => $diffPlacementCount,
            'metrics_a' => [
                'version_id' => (int)$versionA['id'],
                'version_name' => $versionA['version_name'],
                'algorithm_type' => $versionA['algorithm_type'],
                'total_lessons' => $auditA['total_lessons'],
                'scheduled_count' => $auditA['scheduled_count'],
                'unscheduled_count' => $auditA['unscheduled_count'],
                'hard_violations' => $auditA['hard_violations'],
                'soft_penalty_score' => $auditA['soft_penalty_score'],
                'unscheduled_lessons' => $auditA['unscheduled_lessons'],
            ],
            'metrics_b' => [
                'version_id' => (int)$versionB['id'],
                'version_name' => $versionB['version_name'],
                'algorithm_type' => $versionB['algorithm_type'],
                'total_lessons' => $auditB['total_lessons'],
                'scheduled_count' => $auditB['scheduled_count'],
                'unscheduled_count' => $auditB['unscheduled_count'],
                'hard_violations' => $auditB['hard_violations'],
                'soft_penalty_score' => $auditB['soft_penalty_score'],
                'unscheduled_lessons' => $auditB['unscheduled_lessons'],
            ],
            'lesson_rows' => $lessonRows,
        ];
    }
}
