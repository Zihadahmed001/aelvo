<?php
declare(strict_types=1);

namespace Aelvo;

use DomainException;

/** Server policy. Never include approve/execute in the language model's tools. */
final class OwnerAiPolicy
{
    public static function owner(array $actor, int $ownerId): void
    {
        if ($ownerId < 1 || (int)($actor['id'] ?? 0) !== $ownerId ||
            ($actor['is_owner'] ?? false) !== true || ($actor['status'] ?? '') !== 'Active' ||
            empty($actor['email_verified_at']) ||
            !filter_var($actor['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Only the authenticated, email-verified original Owner is allowed.');
        }
    }

    public static function grant(array $grant, int $ownerId, int $now): void
    {
        if (($grant['owner_id'] ?? null) !== $ownerId || empty($grant['nonce']) ||
            ($grant['expires_at'] ?? 0) <= $now || ($grant['last_seen_at'] ?? 0) + 300 <= $now) {
            throw new DomainException('Owner AI verification expired. Verify again.');
        }
    }

    public static function payload(string $operation, array $payload): array
    {
        // No payment/refund/transfer/balance/approval/gateway handler can be registered here.
        $schemas = [
            'catalogue.text.update' => ['product_id', 'name', 'name_bn', 'description', 'description_bn'],
            'content.popup.update' => ['popup_id', 'heading', 'heading_bn', 'body', 'body_bn', 'enabled'],
            'content.banner.update' => ['banner_id', 'heading', 'heading_bn', 'subtitle', 'subtitle_bn', 'media_id'],
            // Inventory/staff/financial operations and code releases remain unavailable.
        ];
        if (!isset($schemas[$operation])) {
            throw new DomainException('This operation is not allowed for Owner AI. Financial actions are guidance-only.');
        }
        if (array_diff(array_keys($payload), $schemas[$operation]) || !$payload) {
            throw new DomainException('Unexpected or empty change fields.');
        }
        foreach ($payload as $value) {
            if (!is_scalar($value) && !is_array($value)) {
                throw new DomainException('Unsupported change value.');
            }
        }
        if (strlen(self::canonical($payload)) > 32768) {
            throw new DomainException('Change is too large.');
        }
        return $payload;
    }

    public static function canonical(array $value): string
    {
        $sort = static function (array $data) use (&$sort): array {
            if (!array_is_list($data)) { ksort($data); }
            foreach ($data as &$item) { if (is_array($item)) { $item = $sort($item); } }
            unset($item);
            return $data;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function digest(string $operation, array $payload, string $baseVersion): string
    {
        return hash('sha256', self::canonical(['operation'=>$operation, 'payload'=>$payload, 'base_version'=>$baseVersion]));
    }
}
