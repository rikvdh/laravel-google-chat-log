<?php

declare(strict_types=1);

namespace Enigma {

    use Illuminate\Container\Container;

    function curl_init(): \stdClass
    {
        return new \stdClass();
    }

    function container(): Container
    {
        static $app = new Container();
        return $app;
    }

    function config(string $option, mixed $default = null)
    {
        if ($option == 'app.name') {
            return 'AppName';
        }
        $container = container();
        return $container->bound('config') ? $container->get('config')->get($option, $default) : $default;
    }

    function curl_setopt_array(object $handle, array $options): bool
    {
        $handle->options = $options;

        return true;
    }
}

namespace Monolog\Handler\Curl {
    final class Util
    {
        /** @var array<int, array<int, mixed>> */
        public static array $executions = [];

        public static function execute(object $handle): void
        {
            self::$executions[] = $handle->options;
        }
    }
}
