<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

/**
 * ReputationService
 *
 * Point values below follow the conventional Stack-Overflow-style scheme
 * referenced in the blueprint (the source PDF's numeric values were lost
 * in extraction, so these are the standard, widely-used defaults — adjust
 * freely via the constants below).
 */
class ReputationService
{
    private const TABLE = 'qa_reputation_history';

    public const POINTS_ASK_QUESTION      = 5;
    public const POINTS_ANSWER_QUESTION   = 10;
    public const POINTS_ACCEPTED_ANSWER   = 15;
    public const POINTS_RECEIVE_UPVOTE    = 10;
    public const POINTS_RECEIVE_DOWNVOTE  = -2;

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    public function award(int $userId, int $amount, string $reason, ?int $sourceId = null, ?string $sourceType = null): void
    {
        if ($amount === 0) {
            return;
        }

        $this->database->table(self::TABLE)->insert([
            'user_id'       => $userId,
            'change_amount' => $amount,
            'reason'        => $reason,
            'source_id'     => $sourceId,
            'source_type'   => $sourceType,
        ]);
    }

    public function awardForAskQuestion(int $userId, int $questionId): void
    {
        $this->award($userId, self::POINTS_ASK_QUESTION, 'question_asked', $questionId, 'question');
    }

    public function awardForAnswerQuestion(int $userId, int $answerId): void
    {
        $this->award($userId, self::POINTS_ANSWER_QUESTION, 'answer_posted', $answerId, 'answer');
    }

    public function awardForAcceptedAnswer(int $userId, int $answerId): void
    {
        $this->award($userId, self::POINTS_ACCEPTED_ANSWER, 'answer_accepted', $answerId, 'answer');
    }

    public function revokeAcceptedAnswer(int $userId, int $answerId): void
    {
        $this->award($userId, -self::POINTS_ACCEPTED_ANSWER, 'answer_unaccepted', $answerId, 'answer');
    }

    public function awardForVoteReceived(int $ownerId, string $votableType, int $votableId, string $voteType): void
    {
        $amount = $voteType === 'upvote' ? self::POINTS_RECEIVE_UPVOTE : self::POINTS_RECEIVE_DOWNVOTE;
        $this->award($ownerId, $amount, 'vote_received_' . $voteType, $votableId, $votableType);
    }

    public function revokeVoteReceived(int $ownerId, string $votableType, int $votableId, string $voteType): void
    {
        $amount = $voteType === 'upvote' ? self::POINTS_RECEIVE_UPVOTE : self::POINTS_RECEIVE_DOWNVOTE;
        $this->award($ownerId, -$amount, 'vote_removed_' . $voteType, $votableId, $votableType);
    }

    public function totalFor(int $userId): int
    {
        $rows = $this->database->table(self::TABLE)
            ->select(['change_amount'])
            ->where('user_id', '=', $userId)
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['change_amount'];
        }
        return $total;
    }

    public function historyFor(int $userId, int $limit = 50): array
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function topContributors(int $limit = 10): array
    {
        // Aggregate reputation across all history rows, grouped by user.
        return $this->database->table(self::TABLE)
            ->select(['user_id', 'SUM(change_amount) AS total'])
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }
}
