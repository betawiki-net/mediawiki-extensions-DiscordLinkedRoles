<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Store;

use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Data-access layer for the discord_linked_roles_account table.
 *
 * Queries use the primary/replica split convention from docs/database.md:
 * - read-only lookups go to the replica
 * - inserts and updates go to the primary
 */
class LinkedAccountStore {

	private const TABLE = 'discord_linked_roles_account';

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {
	}

	/**
	 * Fetch the link record for a given wiki user ID, or null if none exists.
	 */
	private const COLUMNS = [
		'dlra_id',
		'dlra_user_id',
		'dlra_discord_user_id',
		'dlra_discord_username',
		'dlra_refresh_token',
		'dlra_scopes',
		'dlra_connected_at',
		'dlra_updated_at',
		'dlra_last_sync_at',
		'dlra_last_sync_error',
	];

	public function getByUserId( int $userId ): ?LinkedAccountRecord {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( self::COLUMNS )
			->from( self::TABLE )
			->where( [ 'dlra_user_id' => $userId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? $this->rowToRecord( $row ) : null;
	}

	/**
	 * Fetch the link record for a given wiki user ID from the primary DB.
	 * Use this when the caller may have just written a new token and needs
	 * the current value without replica-lag risk.
	 */
	public function getByUserIdFromPrimary( int $userId ): ?LinkedAccountRecord {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$row = $dbw->newSelectQueryBuilder()
			->select( self::COLUMNS )
			->from( self::TABLE )
			->where( [ 'dlra_user_id' => $userId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? $this->rowToRecord( $row ) : null;
	}

	/**
	 * Fetch the link record for a given Discord user ID, or null if none exists.
	 */
	public function getByDiscordUserId( string $discordUserId ): ?LinkedAccountRecord {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( self::COLUMNS )
			->from( self::TABLE )
			->where( [ 'dlra_discord_user_id' => $discordUserId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? $this->rowToRecord( $row ) : null;
	}

	/**
	 * Insert a new link record. Throws on duplicate key violations so callers
	 * can catch and return a friendly 1:1 conflict message.
	 */
	public function insert( LinkedAccountRecord $record ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [
				'dlra_user_id'            => $record->getUserId(),
				'dlra_discord_user_id'    => $record->getDiscordUserId(),
				'dlra_discord_username'   => $record->getDiscordUsername(),
				'dlra_refresh_token'      => $record->getEncryptedRefreshToken(),
				'dlra_scopes'             => $record->getScopes(),
				'dlra_connected_at'       => $record->getConnectedAt(),
				'dlra_updated_at'         => $record->getUpdatedAt(),
				'dlra_last_sync_at'       => $record->getLastSyncAt(),
				'dlra_last_sync_error'    => $record->getLastSyncError(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Update the encrypted refresh token and username for an existing link row.
	 */
	public function updateToken(
		int $userId,
		string $encryptedRefreshToken,
		string $discordUsername
	): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'dlra_refresh_token'    => $encryptedRefreshToken,
				'dlra_discord_username' => $discordUsername,
				'dlra_updated_at'       => MWTimestamp::now( TS_MW ),
			] )
			->where( [ 'dlra_user_id' => $userId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Record the result of a metadata sync attempt.
	 */
	public function updateSyncResult( int $userId, ?string $error ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$set = [ 'dlra_last_sync_error' => $error ];
		if ( $error === null ) {
			$set['dlra_last_sync_at'] = MWTimestamp::now( TS_MW );
		}
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( $set )
			->where( [ 'dlra_user_id' => $userId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Delete the link record for a given wiki user ID.
	 */
	public function deleteByUserId( int $userId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'dlra_user_id' => $userId ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function rowToRecord( object $row ): LinkedAccountRecord {
		return new LinkedAccountRecord(
			(int)$row->dlra_user_id,
			$row->dlra_discord_user_id,
			$row->dlra_discord_username,
			$row->dlra_refresh_token,
			$row->dlra_scopes,
			$row->dlra_connected_at,
			$row->dlra_updated_at,
			$row->dlra_last_sync_at,
			$row->dlra_last_sync_error
		);
	}
}
