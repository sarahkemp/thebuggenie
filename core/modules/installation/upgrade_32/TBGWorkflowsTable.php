<?php

    namespace thebuggenie\core\modules\installation\upgrade_32;

    use thebuggenie\core\entities\tables\ScopedTable;

    /**
     * Workflows table
     *
     * @author Daniel Andre Eikeland <zegenie@zegeniestudios.net>
     * @version 3.1
     * @license http://opensource.org/licenses/MPL-2.0 Mozilla Public License 2.0 (MPL 2.0)
     * @package thebuggenie
     * @subpackage tables
     */

    /**
     * Workflows table
     *
     * @package thebuggenie
     * @subpackage tables
     *
     * @Table(name="workflows")
     */
    class TBGWorkflowsTable extends ScopedTable
    {

        const B2DBNAME = 'workflows';
        const ID = 'workflows.id';
        const SCOPE = 'workflows.scope';
        const NAME = 'workflows.name';
        const DESCRIPTION = 'workflows.description';
        const IS_ACTIVE = 'workflows.is_active';

        protected function initialize()
        {
            parent::setup(self::B2DBNAME, self::ID);
            parent::addInteger(self::SCOPE, 10);
            parent::addVarchar(self::NAME, 200);
            parent::addText(self::DESCRIPTION, false);
            parent::addBoolean(self::IS_ACTIVE);
        }

    }
