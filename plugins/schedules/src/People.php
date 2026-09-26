<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

use App\Core\Db;

/**
 * Names, not accounts.
 *
 * Most people on a rota will never make an account, so a person here is a
 * name somebody typed into a spreadsheet. They are created as they turn up,
 * and two spellings that differ only in case, spacing or accents are one
 * person from the start — the normalized form is the unique key, so the
 * database itself refuses the second copy.
 *
 * A genuine near-duplicate ("Dave" beside "Davey") is a judgement, and is
 * offered to somebody rather than decided here.
 */
final class People
{
    /**
     * The person this spelling means, made if they are new.
     *
     * An alias is checked first: after a merge, the losing spelling still
     * appears in the sheet every week, and resolving it to the person it was
     * merged into is the whole point of keeping it.
     */
    public static function resolve(Db $db, string $name, bool $create = true): ?string
    {
        $key = Names::normalizeName($name);
        if ($key === '') {
            return null;
        }
        $id = $db->value('SELECT id FROM {{people}} WHERE normalized_name = ? AND deleted_at IS NULL', [$key]);
        if (is_string($id)) {
            return $id;
        }
        $viaAlias = $db->value(
            'SELECT p.id FROM {{person_aliases}} a JOIN {{people}} p ON p.id = a.person_id
             WHERE a.normalized_name = ? AND p.deleted_at IS NULL',
            [$key],
        );
        if (is_string($viaAlias)) {
            return $viaAlias;
        }
        if (!$create) {
            return null;
        }
        try {
            return $db->insert('people', ['normalized_name' => $key, 'display_name' => Names::toDisplayName($name)]);
        } catch (\Throwable $e) {
            if (!Db::isDuplicate($e)) {
                throw $e;
            }
            // Two imports racing, or a deleted person with this spelling.
            $existing = $db->value('SELECT id FROM {{people}} WHERE normalized_name = ?', [$key]);
            if (is_string($existing)) {
                $db->update('people', ['deleted_at' => null], ['id' => $existing]);
                return $existing;
            }
            return null;
        }
    }

    /**
     * Fold one person into another: the history moves, and the spelling
     * stays behind as an alias so the next sync resolves it rather than
     * making the duplicate again.
     */
    public static function merge(Db $db, string $keepId, string $loseId): void
    {
        if ($keepId === $loseId) {
            return;
        }
        $db->transaction(function () use ($db, $keepId, $loseId): void {
            $keep = $db->one('SELECT * FROM {{people}} WHERE id = ? FOR UPDATE', [$keepId]);
            $lose = $db->one('SELECT * FROM {{people}} WHERE id = ? FOR UPDATE', [$loseId]);
            if ($keep === null || $lose === null) {
                return;
            }
            foreach ($db->all('SELECT * FROM {{calendar_event_people}} WHERE person_id = ?', [$loseId]) as $row) {
                $clash = $db->value('SELECT id FROM {{calendar_event_people}} WHERE event_id = ? AND person_id = ?', [$row['event_id'], $keepId]);
                if (is_string($clash)) {
                    // Both spellings on the same day: one row, not two.
                    $db->delete('calendar_event_people', ['id' => $row['id']]);
                    continue;
                }
                $db->update('calendar_event_people', ['person_id' => $keepId], ['id' => $row['id']]);
            }
            $db->run('UPDATE {{person_aliases}} SET person_id = ? WHERE person_id = ?', [$keepId, $loseId]);
            // An account belongs to the person who kept it, if the survivor
            // has none of their own.
            if ($keep['user_id'] === null && $lose['user_id'] !== null) {
                $db->update('people', ['user_id' => null], ['id' => $loseId]);
                $db->update('people', ['user_id' => $lose['user_id']], ['id' => $keepId]);
            }
            $alias = (string) $lose['normalized_name'];
            $db->delete('people', ['id' => $loseId]);
            if ($alias !== (string) $keep['normalized_name']) {
                try {
                    $db->insert('person_aliases', ['person_id' => $keepId, 'normalized_name' => $alias]);
                } catch (\Throwable $e) {
                    if (!Db::isDuplicate($e)) {
                        throw $e;
                    }
                }
            }
        });
    }
}
