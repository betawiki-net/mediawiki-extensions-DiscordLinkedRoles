<?php

namespace MediaWiki\Extension\DiscordLinkedRoles;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Hook handlers that must not use services (called during database updates).
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$dbType = $updater->getDB()->getType();

		$updater->addExtensionTable(
			'discord_linked_roles_account',
			"$dir/$dbType/tables-generated.sql"
		);
	}
}
