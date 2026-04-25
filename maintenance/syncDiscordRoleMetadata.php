<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Maintenance;

use Maintenance;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\MetadataDefinitionBuilder;
use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' )
	: realpath( __DIR__ . '/../../../..' );
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Maintenance script to push the Discord application-level role-connection
 * metadata definitions using the configured bot token.
 *
 * Run this after changing $wgDiscordLinkedRolesReportedGroups or after the
 * initial install so that Discord knows which metadata fields the application
 * exposes.
 *
 * Usage:
 *   php extensions/DiscordLinkedRoles/maintenance/SyncDiscordRoleMetadata.php
 */
class SyncDiscordRoleMetadata extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Push the Discord application-level role-connection metadata definitions '
			. 'for all configured $wgDiscordLinkedRolesReportedGroups.'
		);
		$this->requireExtension( 'DiscordLinkedRoles' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$config   = $services->getMainConfig();
		$groups   = $config->get( 'DiscordLinkedRolesReportedGroups' );

		if ( !$groups ) {
			$this->output( "No groups configured in \$wgDiscordLinkedRolesReportedGroups. Nothing to do.\n" );
			return;
		}

		$builder     = new MetadataDefinitionBuilder( $groups );
		$definitions = $builder->build();

		$this->output( sprintf(
			"Pushing %d metadata definition(s) to Discord...\n",
			count( $definitions )
		) );

		foreach ( $definitions as $def ) {
			$this->output( sprintf( "  - %s (%s)\n", $def['key'], $def['name'] ) );
		}

		/** @var \MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient $client */
		$client = $services->get( 'DiscordLinkedRoles.DiscordRoleConnectionClient' );
		$client->putMetadataDefinitions( $definitions );

		$this->output( "Done.\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = SyncDiscordRoleMetadata::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
