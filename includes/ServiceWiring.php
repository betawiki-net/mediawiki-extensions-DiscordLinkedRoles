<?php

/**
 * Service wiring for the DiscordLinkedRoles extension.
 *
 * @file
 */

use MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordOAuthClient;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\UserMetadataBuilder;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [

	'DiscordLinkedRoles.LinkedAccountStore' => static function (
		MediaWikiServices $services
	): LinkedAccountStore {
		return new LinkedAccountStore(
			$services->getConnectionProvider()
		);
	},

	'DiscordLinkedRoles.RefreshTokenEncryptor' => static function (
		MediaWikiServices $services
	): RefreshTokenEncryptor {
		$key = $services->getMainConfig()->get( 'DiscordLinkedRolesEncryptionKey' );
		return new RefreshTokenEncryptor( $key );
	},

	'DiscordLinkedRoles.DiscordOAuthClient' => static function (
		MediaWikiServices $services
	): DiscordOAuthClient {
		$config = $services->getMainConfig();
		return new DiscordOAuthClient(
			$services->getHttpRequestFactory(),
			LoggerFactory::getInstance( 'DiscordLinkedRoles' ),
			$config->get( 'DiscordLinkedRolesClientId' ),
			$config->get( 'DiscordLinkedRolesClientSecret' )
		);
	},

	'DiscordLinkedRoles.DiscordRoleConnectionClient' => static function (
		MediaWikiServices $services
	): DiscordRoleConnectionClient {
		$config = $services->getMainConfig();
		return new DiscordRoleConnectionClient(
			$services->getHttpRequestFactory(),
			LoggerFactory::getInstance( 'DiscordLinkedRoles' ),
			$config->get( 'DiscordLinkedRolesClientId' ),
			$config->get( 'DiscordLinkedRolesBotToken' ),
			$config->get( 'Sitename' )
		);
	},

	'DiscordLinkedRoles.UserMetadataBuilder' => static function (
		MediaWikiServices $services
	): UserMetadataBuilder {
		return new UserMetadataBuilder(
			$services->getUserGroupManager(),
			$services->getMainConfig()->get( 'DiscordLinkedRolesReportedGroups' )
		);
	},

	'DiscordLinkedRoles.RoleConnectionSyncService' => static function (
		MediaWikiServices $services
	): RoleConnectionSyncService {
		return new RoleConnectionSyncService(
			$services->get( 'DiscordLinkedRoles.LinkedAccountStore' ),
			$services->get( 'DiscordLinkedRoles.RefreshTokenEncryptor' ),
			$services->get( 'DiscordLinkedRoles.DiscordOAuthClient' ),
			$services->get( 'DiscordLinkedRoles.DiscordRoleConnectionClient' ),
			$services->get( 'DiscordLinkedRoles.UserMetadataBuilder' ),
			LoggerFactory::getInstance( 'DiscordLinkedRoles' )
		);
	},

];
