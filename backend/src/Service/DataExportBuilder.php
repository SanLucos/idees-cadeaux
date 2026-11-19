<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Friendship;
use App\Entity\FriendSuggestion;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Serializer\FriendshipNormalizer;
use Doctrine\DBAL\Connection;

/**
 * What "Export de mes données" contains (spec §5.13): profile, sizes
 * (with their history), preferences, friendships (pseudos), ideas
 * written (suggestions made to others included), comments, reactions,
 * pledges, reservations made, consents, and the uploaded images.
 *
 * Only what the subject wrote or did — every query filters on its own
 * authorship — so nothing a friend hid on the subject's ideas can get in
 * (règle d'or), nor anyone's private draft, nor another participant's
 * amount (règle 3), nor a `declined` status (règle 4).
 */
final class DataExportBuilder
{
    /** SQL: an idea `i`'s recipients' names (décision 69). */
    private const string RECIPIENT_NAMES = "(SELECT string_agg(ru.display_name, ', ' ORDER BY rn.id) FROM idea_recipient rn JOIN app_user ru ON ru.id = rn.user_id WHERE rn.idea_id = i.id AND rn.deleted_at IS NULL)";

    /** SQL: idea `i`'s author is one of its recipients (not a suggestion). */
    private const string PERSONAL = 'EXISTS (SELECT 1 FROM idea_recipient ra WHERE ra.idea_id = i.id AND ra.user_id = i.author_id AND ra.deleted_at IS NULL)';

    public function __construct(
        private readonly Connection $db,
        private readonly FriendshipRepository $friendships,
        private readonly FriendshipNormalizer $friendshipNormalizer,
        private readonly FriendSuggestions $suggestions,
    ) {
    }

    /**
     * @return array{json: array<string, mixed>, images: array<string, string>} file name → data; archive path → storage path
     */
    public function build(User $subject): array
    {
        $id = $subject->getId()->toRfc4122();
        $images = [];
        if (null !== $subject->getAvatarPath()) {
            $images['images/avatar.jpg'] = $subject->getAvatarPath();
        }

        $ideas = $this->rows(
            'SELECT i.id, i.title, i.url, i.price_amount, i.price_currency, i.note, o.code AS occasion, i.visibility, i.status,
                    i.archive_kind, i.published_at, i.archived_at, i.created_at, i.updated_at, i.image_path,
                    CASE WHEN NOT '.self::PERSONAL.' THEN '.self::RECIPIENT_NAMES.' END AS suggested_to,
                    '.self::RECIPIENT_NAMES.' AS recipients
             FROM idea i
             LEFT JOIN occasion o ON o.id = i.occasion_id
             WHERE i.deleted_at IS NULL AND (i.author_id = :id
                -- Shared with me by another recipient (décision 69): mine too, owner view.
                OR (i.visibility = \'published\' AND EXISTS (SELECT 1 FROM idea_recipient rm WHERE rm.idea_id = i.id AND rm.user_id = :id AND rm.deleted_at IS NULL) AND '.self::PERSONAL.'))
             ORDER BY i.created_at',
            $id,
        );
        foreach ($ideas as &$idea) {
            if (null !== $idea['image_path']) {
                $idea['image'] = 'images/ideas/'.$idea['id'].'.jpg';
                $images[$idea['image']] = (string) $idea['image_path'];
            }
            unset($idea['image_path']);
        }
        unset($idea);

        return [
            'json' => [
                'profile.json' => [
                    'id' => $id,
                    'type' => $subject->getType()->value,
                    'email' => $subject->getEmail(),
                    'displayName' => $subject->getDisplayName(),
                    'birthDay' => $subject->getBirthDay(),
                    'birthMonth' => $subject->getBirthMonth(),
                    'birthYear' => $subject->getBirthYear(),
                    'locale' => $subject->getLocale(),
                    'timezone' => $subject->getTimezone(),
                    'managedBy' => $subject->getManagersDisplayName(),
                    'createdAt' => $subject->getCreatedAt()->format(\DATE_ATOM),
                    'avatar' => isset($images['images/avatar.jpg']) ? 'images/avatar.jpg' : null,
                    'exportedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ],
                'sizes.json' => $this->sizes($id),
                'preferences.json' => $this->rows('SELECT category, label, value, created_at FROM profile_preference WHERE user_id = :id AND deleted_at IS NULL ORDER BY category, created_at', $id),
                'friends.json' => $this->friends($subject),
                'ideas.json' => $ideas,
                'comments.json' => $this->rows(
                    'SELECT c.body, c.created_at, c.edited_at, i.title AS idea, '.self::RECIPIENT_NAMES.' AS idea_owner
                     FROM comment c JOIN idea i ON i.id = c.idea_id
                     WHERE c.author_id = :id AND c.deleted_at IS NULL ORDER BY c.created_at',
                    $id,
                ),
                'reactions.json' => $this->rows(
                    'SELECT r.type, r.created_at, i.title AS idea, '.self::RECIPIENT_NAMES.' AS idea_owner
                     FROM reaction r JOIN idea i ON i.id = r.idea_id
                     WHERE r.user_id = :id AND r.deleted_at IS NULL ORDER BY r.created_at',
                    $id,
                ),
                'reservations.json' => $this->rows(
                    'SELECT r.created_at, i.title AS idea, '.self::RECIPIENT_NAMES.' AS idea_owner
                     FROM reservation r JOIN idea i ON i.id = r.idea_id
                     WHERE r.user_id = :id AND r.deleted_at IS NULL ORDER BY r.created_at',
                    $id,
                ),
                'contributions.json' => [
                    // My own pledges only: never someone else's amount.
                    'pledges' => $this->rows(
                        'SELECT p.amount, c.currency, p.created_at, i.title AS idea, '.self::RECIPIENT_NAMES.' AS idea_owner
                         FROM contribution_pledge p JOIN contribution c ON c.id = p.contribution_id
                         JOIN idea i ON i.id = c.idea_id
                         WHERE p.user_id = :id AND p.deleted_at IS NULL ORDER BY p.created_at',
                        $id,
                    ),
                    'initiated' => $this->rows(
                        'SELECT c.target_amount, c.currency, c.status, c.created_at, c.closed_at, i.title AS idea, '.self::RECIPIENT_NAMES.' AS idea_owner
                         FROM contribution c JOIN idea i ON i.id = c.idea_id
                         WHERE c.initiator_id = :id AND c.deleted_at IS NULL ORDER BY c.created_at',
                        $id,
                    ),
                ],
                'consents.json' => [
                    'parentalConsentAt' => $subject->getParentalConsentAt()?->format(\DATE_ATOM),
                    'notifications' => $this->rows(
                        'SELECT channel, type, enabled, consented_at FROM notification_preference WHERE user_id = :id AND deleted_at IS NULL ORDER BY channel, type',
                        $id,
                    ),
                ],
            ],
            'images' => $images,
        ];
    }

    /**
     * Sizes with every value they have had (spec §11 décision 51): the
     * subject's own history, which nobody else reads.
     *
     * @return list<array<string, mixed>>
     */
    private function sizes(string $id): array
    {
        $history = [];
        foreach ($this->rows(
            'SELECT h.size_id, h.value, h.unit, h.created_at AS since
             FROM profile_size_history h JOIN profile_size s ON s.id = h.size_id
             WHERE s.user_id = :id AND s.deleted_at IS NULL AND h.deleted_at IS NULL ORDER BY h.created_at, h.id',
            $id,
        ) as $entry) {
            $history[(string) $entry['size_id']][] = ['value' => $entry['value'], 'unit' => $entry['unit'], 'since' => $entry['since']];
        }

        $sizes = $this->rows('SELECT id, label, value, unit, note, sort_order, created_at FROM profile_size WHERE user_id = :id AND deleted_at IS NULL ORDER BY sort_order', $id);
        foreach ($sizes as &$size) {
            $size['history'] = $history[(string) $size['id']] ?? [];
            unset($size['id']);
        }
        unset($size);

        return $sizes;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function friends(User $subject): array
    {
        $person = fn (Friendship $f): ?string => $f->otherParty($subject)->getDisplayName();

        return [
            'friends' => array_map(fn (Friendship $f) => [
                'displayName' => $person($f),
                'since' => $f->getRespondedAt()?->format(\DATE_ATOM),
                'origin' => $f->getOrigin()->value,
            ], $this->friendships->findAccepted($subject)),
            // Status as the requester sees it: `declined` masked (règle 4).
            'requestsSent' => array_map(fn (Friendship $f) => [
                'displayName' => $person($f),
                'status' => $this->friendshipNormalizer->normalize($f, $subject)['status'],
                'sentAt' => $f->getCreatedAt()->format(\DATE_ATOM),
            ], $this->friendships->findOutgoingVisible($subject)),
            'requestsReceived' => array_map(fn (Friendship $f) => [
                'displayName' => $person($f),
                'receivedAt' => $f->getCreatedAt()->format(\DATE_ATOM),
            ], $this->friendships->findPendingIncoming($subject)),
            // Spec §11 décision 65: still pending, as each side sees them.
            'suggestionsMade' => array_map(fn (FriendSuggestion $s) => [
                'to' => $s->getRecipient()->getDisplayName(),
                'suggested' => $s->getSuggested()->getDisplayName(),
                'status' => 'pending',
                'createdAt' => $s->getCreatedAt()->format(\DATE_ATOM),
            ], $this->suggestions->openBy($subject)),
            'suggestionsReceived' => array_map(fn (FriendSuggestion $s) => [
                'from' => $s->getSuggestedBy()->getDisplayName(),
                'suggested' => $s->getSuggested()->getDisplayName(),
                'createdAt' => $s->getCreatedAt()->format(\DATE_ATOM),
            ], $this->suggestions->visibleTo($subject)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $id): array
    {
        return $this->db->fetchAllAssociative($sql, ['id' => $id]);
    }
}
