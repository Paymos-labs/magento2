<?php

declare(strict_types=1);

/**
 * Minimal Magento framework stubs — only the symbols the crypto-critical Service
 * classes touch when run outside a Magento install (EventStore's
 * ResourceConnection + two DB exception types). NOT a Magento emulation; the
 * Magento-glue classes (MagentoOrderGateway, InvoiceSnapshotRepository) are
 * intentionally NOT exercised by the unit tests. The checkout Redirect
 * controller is, through the bare result/session/order/message types at the
 * end of this file: what the buyer is told after a failed start is decided there.
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

            public function __construct(?\Paymos\Payment\Tests\FakeDbConnection $connection = null)
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

namespace Magento\Store\Model {
    if (!class_exists(ScopeInterface::class)) {
        class ScopeInterface
        {
            const SCOPE_STORE = 'store';
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class)) {
        interface ScopeConfigInterface
        {
            /**
             * @param string $path
             * @param string|null $scope
             * @param int|string|null $scopeId
             * @return mixed
             */
            public function getValue($path, $scope = null, $scopeId = null);

            /**
             * @param string $path
             * @param string|null $scope
             * @param int|string|null $scopeId
             * @return bool
             */
            public function isSetFlag($path, $scope = null, $scopeId = null);
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(Phrase::class)) {
        /**
         * Minimal Phrase: stringifiable, with %1..%n positional substitution.
         */
        class Phrase
        {
            /** @var string */
            private $text;

            /** @var array<int, mixed> */
            private $arguments;

            /**
             * @param string $text
             * @param array<int, mixed> $arguments
             */
            public function __construct($text, array $arguments = [])
            {
                $this->text = (string) $text;
                $this->arguments = $arguments;
            }

            public function __toString()
            {
                $out = $this->text;
                foreach ($this->arguments as $i => $argument) {
                    $out = str_replace('%' . ($i + 1), (string) $argument, $out);
                }

                return $out;
            }

            public function render()
            {
                return $this->__toString();
            }
        }
    }
}

namespace {
    if (!function_exists('__')) {
        /**
         * @param string $text
         * @return \Magento\Framework\Phrase
         */
        function __($text)
        {
            return new \Magento\Framework\Phrase($text, array_slice(func_get_args(), 1));
        }
    }
}

namespace Magento\Framework\Url {
    if (!class_exists(Url::class)) {
        class Url
        {
            /** @var string */
            private $base;

            public function __construct($base = 'https://store.example/')
            {
                $this->base = rtrim((string) $base, '/') . '/';
            }

            public function getBaseUrl()
            {
                return $this->base;
            }

            public function getUrl($path = '')
            {
                return $this->base . ltrim((string) $path, '/');
            }
        }
    }
}

namespace Magento\Backend\Block\Template {
    if (!class_exists(Context::class)) {
        class Context
        {
            /** @var \Magento\Framework\Url\Url */
            public $urlBuilder;

            public function __construct($urlBuilder = null)
            {
                $this->urlBuilder = $urlBuilder ?: new \Magento\Framework\Url\Url();
            }

            public function getUrlBuilder()
            {
                return $this->urlBuilder;
            }
        }
    }
}

namespace Magento\Framework\Data\Form\Element {
    if (!class_exists(AbstractElement::class)) {
        class AbstractElement
        {
        }
    }
}

namespace Magento\Config\Block\System\Config\Form {
    if (!class_exists(Field::class)) {
        class Field
        {
            /** @var \Magento\Backend\Block\Template\Context */
            protected $context;

            /** @var \Magento\Framework\Url\Url */
            protected $_urlBuilder;

            /** @var array<string, mixed> */
            protected $data = [];

            /**
             * @param \Magento\Backend\Block\Template\Context $context
             * @param array<string, mixed> $data
             */
            public function __construct($context, array $data = [])
            {
                $this->context = $context;
                $this->_urlBuilder = $context->getUrlBuilder();
                $this->data = $data;
            }

            protected function escapeHtml($value)
            {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', false);
            }

            public function getUrl($path = '')
            {
                return $this->_urlBuilder->getUrl($path);
            }

            protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element): string
            {
                return '';
            }
        }
    }
}

namespace Magento\Checkout\Model {
    if (!interface_exists(ConfigProviderInterface::class)) {
        interface ConfigProviderInterface
        {
        }
    }
}

namespace Magento\Backend\App {
    if (!class_exists(Action::class)) {
        class Action
        {
        }
    }
}

namespace Magento\Framework\App\Action {
    if (!interface_exists(HttpPostActionInterface::class)) {
        interface HttpPostActionInterface
        {
        }
    }
    if (!interface_exists(HttpGetActionInterface::class)) {
        interface HttpGetActionInterface
        {
        }
    }
}

namespace Magento\Framework\App {
    if (!interface_exists(CsrfAwareActionInterface::class)) {
        interface CsrfAwareActionInterface
        {
        }
    }
    if (!class_exists(Request\Http::class) && !class_exists('Magento\Framework\App\Request\Http')) {
        // placeholder replaced below
    }
}

namespace Magento\Framework\App\Request {
    if (!class_exists(Http::class)) {
        class Http
        {
        }
    }
    if (!class_exists(InvalidRequestException::class)) {
        class InvalidRequestException extends \Exception
        {
        }
    }
}

namespace Magento\Framework\App\Response {
    if (!class_exists(Http::class)) {
        class Http
        {
            public function setHttpResponseCode($code) { return $this; }
            public function setHeader($name, $value, $replace = true) { return $this; }
            public function setBody($body) { return $this; }
        }
    }
}

namespace Magento\Framework\Data {
    if (!interface_exists(OptionSourceInterface::class)) {
        interface OptionSourceInterface
        {
        }
    }
}

namespace Magento\Framework\DB {
    if (!class_exists(Transaction::class)) {
        class Transaction
        {
        }
    }
}

namespace Magento\Framework\Logger\Handler {
    if (!class_exists(Base::class)) {
        class Base
        {
        }
    }
}

namespace Magento\Payment\Gateway {
    if (!interface_exists(CommandInterface::class)) {
        interface CommandInterface
        {
        }
    }
}

namespace Magento\Payment\Gateway\Config {
    if (!class_exists(Config::class)) {
        class Config
        {
        }
    }
}

namespace Magento\Payment\Gateway\Helper {
    if (!class_exists(SubjectReader::class)) {
        class SubjectReader
        {
        }
    }
}

namespace Magento\Sales\Api {
    if (!interface_exists(OrderRepositoryInterface::class)) {
        interface OrderRepositoryInterface
        {
        }
    }
}

namespace Magento\Framework\App\Config\Storage {
    if (!interface_exists(WriterInterface::class)) {
        interface WriterInterface
        {
        }
    }
}

namespace Magento\Framework\App\Cache {
    if (!class_exists(Type::class)) {
        class Type
        {
        }
    }
}

namespace Magento\Framework\App\Cache\Type {
    if (!class_exists(Config::class)) {
        class Config extends \Magento\Framework\App\Cache\Type
        {
        }
    }
}

namespace Magento\Framework\Encryption {
    if (!interface_exists(EncryptorInterface::class)) {
        interface EncryptorInterface
        {
        }
    }
}

namespace Magento\Framework\Exception {
    if (!class_exists(NoSuchEntityException::class)) {
        class NoSuchEntityException extends \Exception
        {
        }
    }
}

namespace Magento\Framework {
    if (!interface_exists(UrlInterface::class)) {
        interface UrlInterface
        {
        }
    }
}

namespace Magento\Framework\Component {
    if (!class_exists(ComponentRegistrar::class)) {
        class ComponentRegistrar
        {
            const MODULE = 'module';

            public static function register($type, $name, $path)
            {
            }
        }
    }
}

namespace Magento\Store\Model {
    if (!class_exists(StoreManagerInterface::class) && !interface_exists(StoreManagerInterface::class)) {
        interface StoreManagerInterface
        {
        }
    }
}

namespace Magento\Checkout\Model\Session {
    if (!class_exists(Session::class)) {
        class Session
        {
        }
    }
}

namespace Monolog {
    if (!class_exists(Logger::class)) {
        class Logger
        {
            public function info($message, array $context = array()) {}
            public function warning($message, array $context = array()) {}
        }
    }
}

namespace Magento\Framework\Controller\Result {
    if (!class_exists(Redirect::class)) {
        class Redirect
        {
            /** @var string */
            public $path = '';

            /** @var string */
            public $url = '';

            public function setPath($path, array $params = array())
            {
                $this->path = (string) $path;
                return $this;
            }

            public function setUrl($url)
            {
                $this->url = (string) $url;
                return $this;
            }
        }
    }
}

namespace Magento\Framework\Controller {
    if (!class_exists(ResultFactory::class)) {
        class ResultFactory
        {
            const TYPE_REDIRECT = 'redirect';

            public function create($type, array $arguments = array())
            {
                return new Result\Redirect();
            }
        }
    }
}

namespace Magento\Framework\Message {
    if (!interface_exists(ManagerInterface::class)) {
        interface ManagerInterface
        {
            public function addErrorMessage($message, $group = null);
        }
    }
}

namespace Magento\Checkout\Model {
    if (!class_exists(Session::class)) {
        class Session
        {
            public function getLastRealOrder()
            {
                return null;
            }

            public function restoreQuote()
            {
                return false;
            }
        }
    }
}

namespace Magento\Sales\Model {
    if (!class_exists(Order::class)) {
        class Order
        {
        }
    }
}
