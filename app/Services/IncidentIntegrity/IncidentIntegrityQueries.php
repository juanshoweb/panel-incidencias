<?php

namespace App\Services\IncidentIntegrity;

/**
 * SQL executed by the diagnosis. The strings are the source of truth documented
 * in docs/integridad-incidencias.md. Classification that is not null-safe in
 * SQL (status inequality, canonical JSON) happens in PHP on these result sets.
 */
class IncidentIntegrityQueries
{
    /**
     * Resolved incidents with no status_changed log whose new_value is resolved.
     * A later reopen does not satisfy this query: the resolution log still exists.
     */
    public static function resolvedWithoutResolutionLog(): string
    {
        return <<<'SQL'
SELECT i.id AS incident_id, i.title, i.closed_at
FROM incidents AS i
WHERE i.status = 'resolved'
  AND NOT EXISTS (
      SELECT 1
      FROM incident_logs AS l
      WHERE l.incident_id = i.id
        AND l.action = 'status_changed'
        AND l.new_value = 'resolved'
  )
SQL;
    }

    /**
     * One row per incident that has at least one status-changing log.
     * "Latest" is created_at DESC, id DESC. Both status_changed and reopened
     * count: ignoring reopen would flag every reopened incident as divergent.
     */
    public static function latestStatusLogPerIncident(): string
    {
        return <<<'SQL'
SELECT
    i.id AS incident_id,
    i.title,
    i.status AS current_status,
    i.closed_at,
    latest.id AS log_id,
    latest.action AS logged_action,
    latest.old_value,
    latest.new_value AS logged_status,
    latest.created_at AS logged_at
FROM incidents AS i
INNER JOIN incident_logs AS latest
    ON latest.id = (
        SELECT l.id
        FROM incident_logs AS l
        WHERE l.incident_id = i.id
          AND l.action IN ('status_changed', 'reopened')
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 1
    )
SQL;
    }

    public static function incidents(): string
    {
        return <<<'SQL'
SELECT id AS incident_id, title, status, closed_at
FROM incidents
ORDER BY id ASC
SQL;
    }

    /**
     * Full log scan. Duplicate grouping and chain checks run on this result
     * so metadata can be compared after canonical JSON key ordering.
     */
    public static function logsInOrder(): string
    {
        return <<<'SQL'
SELECT id, incident_id, action, old_value, new_value, user_name, metadata, created_at
FROM incident_logs
ORDER BY incident_id ASC, created_at ASC, id ASC
SQL;
    }
}
