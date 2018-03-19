<?php
namespace OC\Migrations;

use OCP\IDBConnection;
use OCP\Migration\ISqlMigration;

/**
 * Sets empty authtoken names to '(none)'
 * https://github.com/owncloud/core/issues/30792
 */
class Version20180319102121 implements ISqlMigration {

	public function sql(IDBConnection $connection) {
		$q = $connection->getQueryBuilder();
		$q->automaticTablePrefix(true);

		$q->update('authtoken')
			->set('name', '(none)')
			->where("name = ''")
			->orWhere('name IS NULL')
			->execute();
    }
}
