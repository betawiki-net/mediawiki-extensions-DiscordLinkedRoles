<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Sync;

use InvalidArgumentException;

/**
 * Builds the Discord application-level role connection metadata definition array
 * from the configured list of wiki groups.
 */
class MetadataDefinitionBuilder {

	/**
	 * Discord metadata type: BOOLEAN_EQUAL (value 1 = user qualifies, 0 = does not).
	 */
	private const BOOLEAN_EQUAL = 7;

	private const MAX_GROUPS = 5;

	/**
	 * @param string[] $groups Ordered list of wiki group names from config.
	 * @throws InvalidArgumentException if more than 5 groups are provided.
	 */
	public function __construct( private readonly array $groups ) {
		if ( count( $groups ) > self::MAX_GROUPS ) {
			throw new InvalidArgumentException(
				'$wgDiscordLinkedRolesReportedGroups may contain at most ' . self::MAX_GROUPS
				. ' groups; ' . count( $groups ) . ' given.'
			);
		}
	}

	/**
	 * Derive a stable, Discord-safe metadata key from a wiki group name.
	 *
	 * Discord metadata keys must match [a-z0-9_] and be at most 50 characters.
	 * The `group_` prefix ensures keys are never empty even for unusual names.
	 */
	public static function groupToKey( string $group ): string {
		$safe = preg_replace( '/[^a-z0-9]+/', '_', strtolower( $group ) );
		return substr( 'group_' . $safe, 0, 50 );
	}

	/**
	 * Build the array of metadata definitions to send to the Discord API.
	 *
	 * Name and description are resolved from MediaWiki i18n messages so
	 * administrators can override them in the MediaWiki: namespace. The
	 * metadata name comes from the core group message `group-{group}` and
	 * the description key follows the pattern:
	 *   - discordlinkedroles-group-{key}-desc (falls back to a generic message)
	 *
	 * @return array[] Array of Discord metadata definition records.
	 */
	public function build(): array {
		$definitions = [];
		foreach ( $this->groups as $group ) {
			$key     = self::groupToKey( $group );
			$nameMsg = wfMessage( 'group-' . $group );
			$descMsg = wfMessage( 'discordlinkedroles-group-' . $key . '-desc' );

			$name = $nameMsg->exists() ? $nameMsg->plain() : $group;

			$definitions[] = [
				'key'         => $key,
				'name'        => $name,
				'description' => $descMsg->exists()
					? $descMsg->plain()
					: wfMessage( 'discordlinkedroles-group-default-desc', $name )->plain(),
				'type'        => self::BOOLEAN_EQUAL,
			];
		}
		return $definitions;
	}
}
