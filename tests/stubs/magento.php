<?php

declare(strict_types=1);

/**
 * Minimal Magento framework stubs — only the symbols the crypto-critical Service
 * classes touch when run outside a Magento install (EventStore's
 * ResourceConnection + two DB exception types). NOT a Magento emulation; the
 * Magento-glue classes (MagentoOrderGateway, InvoiceSnapshotRepository, the
 * controllers) are intentionally NOT loaded by the unit tests.
 */

namespace Magento\Framework\Exception {
    if (!class_exists(AlreadyExistsException::class)) {
        class AlreadyExistsException extends \Exception
        {
        }
    }
}

namespace Magento\Framework\DB\Adapter {
    if (!class_exists(DuplicateException::class)) {
        class DuplicateException extends \Exception
        {
        }
    }
}

namespace Magento\Framework\App {
    if (!class_exists(ResourceConnection::class)) {
        /**
         * In-memory stand-in for ResourceConnection backed by a single fake
         * adapter (see \Paymos\Payment\Tests\FakeDbConnection).
         */
        class ResourceConnection
        {
            /** @var \Paymos\Payment\Tests\FakeDbConnection */
            private $connection;

            public function __construct(\Paymos\Payment\Tests\FakeDbConnection $connection = null)
            {
                $this->connection = $connection ?: new \Paymos\Payment\Tests\FakeDbConnection();
            }

            public function getConnection($resourceName = 'default')
            {
                return $this->connection;
            }

            public function getTableName($tableName, $connectionName = 'default')
            {
                return (string) $tableName;
            }
        }
    }
}
