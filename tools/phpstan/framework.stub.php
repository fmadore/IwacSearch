<?php

/**
 * Minimal signatures for the Laminas + Doctrine DBAL classes this module
 * touches.
 *
 * Why stubs rather than dev dependencies: Omeka S pins its own Laminas
 * versions, so a `require-dev` copy would let PHPStan analyse against
 * signatures that differ from the ones the module actually runs on — the
 * failure mode a static-analysis gate is supposed to prevent. (There is also
 * a hard blocker: `laminas/laminas-log` 2.17, the version Omeka S 4 ships,
 * declares `php ~8.1 || ~8.2 || ~8.3` and so cannot be installed on the 8.4
 * leg of the CI matrix.)
 *
 * Same editing rules as omeka.stub.php: copy signatures from the real source,
 * add only what the module references, and stay loose where the framework is
 * loose. This file plus omeka.stub.php is the complete list of framework
 * surface IwacSearch depends on.
 */

namespace Laminas\ModuleManager {
    class ModuleManager
    {
    }
}

namespace Laminas\ModuleManager\Feature {
    interface ConfigProviderInterface
    {
        public function getConfig();
    }
}

namespace Laminas\EventManager {
    class Event
    {
        public function getParam($name, $default = null);

        public function getTarget();

        public function getName();
    }

    interface SharedEventManagerInterface
    {
        public function attach($identifier, $event, callable $listener, $priority = 1);
    }
}

namespace Laminas\Mvc {
    class MvcEvent extends \Laminas\EventManager\Event
    {
        public function getApplication(): \Laminas\Mvc\Application;
    }

    class Application
    {
        public function getServiceManager(): \Laminas\ServiceManager\ServiceLocatorInterface;
    }
}

namespace Laminas\Mvc\Controller {
    abstract class AbstractActionController
    {
        public function getRequest();

        public function getResponse();

        /** @return mixed */
        public function __call($method, $params);

        /** @return \Laminas\Mvc\Controller\Plugin\Params */
        public function params();

        /** @return \Laminas\Mvc\Controller\Plugin\Redirect */
        public function redirect();

        /** @return \Laminas\Mvc\Controller\Plugin\Url */
        public function url();
    }
}

namespace Laminas\Mvc\Controller\Plugin {
    class Params
    {
        public function fromQuery($name = null, $default = null);

        public function fromRoute($name = null, $default = null);

        public function fromPost($name = null, $default = null);
    }

    class Redirect
    {
        public function toRoute($route = null, $params = [], $options = [], $reuseMatchedParams = false);
    }

    class Url
    {
        public function fromRoute($route = null, $params = [], $options = [], $reuseMatchedParams = false);
    }
}

namespace Laminas\ServiceManager {
    interface ServiceLocatorInterface extends \Psr\Container\ContainerInterface
    {
    }
}

namespace Laminas\ServiceManager\Factory {
    interface FactoryInterface
    {
        public function __invoke(\Psr\Container\ContainerInterface $container, $requestedName, ?array $options = null);
    }
}

namespace Laminas\Http {
    class Response
    {
        public const STATUS_CODE_503 = 503;

        public function getHeaders();

        public function setStatusCode($code);
    }

    class Request
    {
        public function isPost();

        public function getPost($name = null, $default = null);
    }
}

namespace Laminas\View\Model {
    class ViewModel
    {
        /** @param array<string, mixed> $variables */
        public function __construct($variables = null, $options = null);

        public function setTemplate($template);
    }

    class JsonModel extends ViewModel
    {
    }
}

namespace Laminas\View\Renderer {
    class PhpRenderer
    {
        /** @return mixed */
        public function __call($method, $argv);

        public function render($nameOrModel, $values = null);
    }
}

namespace Laminas\View\Helper {
    abstract class AbstractHelper
    {
        public function setView(\Laminas\View\Renderer\PhpRenderer $view);

        /** @return \Laminas\View\Renderer\PhpRenderer */
        public function getView();
    }
}

namespace Laminas\Form {
    class Form
    {
        public function init();

        /** @param array<string, mixed>|\Laminas\Form\ElementInterface $elementOrFieldset */
        public function add($elementOrFieldset, array $flags = []);

        /** @param array<string, mixed> $data */
        public function setData($data);

        public function isValid();
    }

    interface ElementInterface
    {
    }
}

namespace Laminas\Form\Element {
    class Csrf implements \Laminas\Form\ElementInterface
    {
    }
}

namespace Laminas\Permissions\Acl {
    class Acl
    {
        public function allow($roles = null, $resources = null, $privileges = null, ?object $assert = null);
    }
}

namespace Laminas\Log {
    interface LoggerInterface
    {
        public function log($priority, $message, $extra = []);
    }
}

namespace Laminas\Router\Http {
    class Literal
    {
    }

    class Segment
    {
    }
}

namespace Doctrine\DBAL {
    class Connection
    {
        /**
         * @param array<int|string, mixed> $params
         * @param array<int|string, mixed> $types
         */
        public function executeQuery(string $sql, array $params = [], array $types = []): Result;

        /**
         * @param array<int|string, mixed> $params
         * @param array<int|string, mixed> $types
         */
        public function executeStatement(string $sql, array $params = [], array $types = []): int|string;

        /**
         * @param array<int|string, mixed> $params
         * @param array<int|string, mixed> $types
         * @return list<mixed>
         */
        public function fetchFirstColumn(string $sql, array $params = [], array $types = []): array;
    }

    class Result
    {
        /** @return list<array<string, mixed>> */
        public function fetchAllAssociative(): array;
    }

    enum ArrayParameterType: int
    {
        case INTEGER = 1;
        case STRING = 2;
        case ASCII = 3;
        case BINARY = 4;
    }

    class DriverManager
    {
        /** @param array<string, mixed> $params */
        public static function getConnection(array $params): Connection;
    }
}
