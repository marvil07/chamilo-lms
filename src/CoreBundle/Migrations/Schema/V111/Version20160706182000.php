<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Migrations\Schema\V111;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

/**
 * Class Version20160706182000
 * Add new table to save user visibility on courses in the catalogue.
 *
 * @package Chamilo\CoreBundle\Migrations\Schema\V111
 */
class Version20160706182000 extends AbstractMigrationChamilo
{
    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    public function up(Schema $schema)
    {
        $this->addSql(
             'CREATE TABLE IF NOT EXISTS course_rel_user_catalogue (id int NOT NULL AUTO_INCREMENT, user_id int DEFAULT NULL, c_id int DEFAULT NULL, visible int NOT NULL, PRIMARY KEY (id), KEY (user_id), KEY (c_id), CONSTRAINT FOREIGN KEY (c_id) REFERENCES course (id) ON DELETE CASCADE, CONSTRAINT FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci'
         );
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    public function down(Schema $schema)
    {
        $this->addSql(
            'DROP TABLE course_rel_user_catalogue'
        );
    }
}
