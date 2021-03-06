<?php
/**
 * Application - Implements the Application.
 *
 * @author Virgil-Adrian Teaca - virgil@giulianaeassociati.com
 * @version 3.0
 */

namespace Core;

use Illuminate\Container\Container;
use Core\Providers as ProviderRepository;
use Http\Request;
use Http\Response;

use Events\EventServiceProvider;
use Routing\RoutingServiceProvider;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;


class Application extends Container
{
    /**
     * All of the registered service providers.
     *
     * @var array
     */
    protected $serviceProviders = array();

    /**
     * The names of the loaded service providers.
     *
     * @var array
     */
    protected $loadedProviders = array();

    /**
     * The deferred services and their providers.
     *
     * @var array
     */
    protected $deferredServices = array();

    /**
     * The Request class used by the application.
     *
     * @var string
     */
    protected static $requestClass = 'Http\Request';


    /**
     * Create a new application instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->registerBaseBindings($request ?: $this->createNewRequest());

        $this->registerBaseServiceProviders();
    }

    /**
     * Create a new Request instance from the Request class.
     *
     * @return \Http\Request
     */
    protected function createNewRequest()
    {
        return forward_static_call(array(static::$requestClass, 'createFromGlobals'));
    }

    /**
     * Register the basic bindings into the Container.
     *
     * @param  \Http\Request  $request
     * @return void
     */
    protected function registerBaseBindings(Request $request)
    {
        $this->instance('request', $request);

        $this->instance('Illuminate\Container\Container', $this);
    }

    /**
     * Register all of the base Service Providers.
     *
     * @return void
     */
    protected function registerBaseServiceProviders()
    {
        foreach (array('Event', 'Exception', 'Routing') as $name) {
            $this->{"register{$name}Provider"}();
        }
    }

    /**
     * Register the Exception Service Provider.
     *
     * @return void
     */
    protected function registerExceptionProvider()
    {
        //$this->register(new ExceptionServiceProvider($this));
    }

    /**
     * Register the Routing Service Provider.
     *
     * @return void
     */
    protected function registerRoutingProvider()
    {
        $this->register(new RoutingServiceProvider($this));
    }

    /**
     * Register the Event Service Provider.
     *
     * @return void
     */
    protected function registerEventProvider()
    {
        $this->register(new EventServiceProvider($this));
    }

    /**
     * Bind the installation paths to the application.
     *
     * @param  string  $paths
     * @return string
     */
    public function bindInstallPaths(array $paths)
    {
        $this->instance('path', realpath($paths['app']));

        foreach ($paths as $key => $value) {
            $this->instance("path.{$key}", realpath($value));
        }
    }

    /**
     * Register a service provider with the application.
     *
     * @param  \Support\ServiceProvider|string  $provider
     * @param  array  $options
     * @param  bool  $force
     * @return \Support\ServiceProvider
     */
    public function register($provider, $options = array(), $force = false)
    {
        if ($registered = $this->getRegistered($provider) && ! $force) {
            return $registered;
        }

        if (is_string($provider)) {
            $provider = $this->resolveProviderClass($provider);
        }

        $provider->register();

        foreach ($options as $key => $value) {
            $this[$key] = $value;
        }

        $this->markAsRegistered($provider);

        return $provider;
    }

    /**
     * Get the registered Service Provider instance if it exists.
     *
     * @param  \Support\ServiceProvider|string  $provider
     * @return \Support\ServiceProvider|null
     */
    public function getRegistered($provider)
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        if (array_key_exists($name, $this->loadedProviders)) {
            return array_first($this->serviceProviders, function($key, $value) use ($name)
            {
                return (get_class($value) == $name);
            });
        }
    }

    /**
     * Resolve a Service Provider instance from the class name.
     *
     * @param  string  $provider
     * @return \Support\ServiceProvider
     */
    public function resolveProviderClass($provider)
    {
        return new $provider($this);
    }

    /**
     * Mark the given provider as registered.
     *
     * @param  \Support\ServiceProvider
     * @return void
     */
    protected function markAsRegistered($provider)
    {
        $class = get_class($provider);

        $this->serviceProviders[] = $provider;

        $this->loadedProviders[$class] = true;
    }

    /**
     * Load and boot all of the remaining deferred providers.
     *
     * @return void
     */
    public function loadDeferredProviders()
    {
        foreach ($this->deferredServices as $service => $provider) {
            $this->loadDeferredProvider($service);
        }

        $this->deferredServices = array();
    }

    /**
     * Load the provider for a deferred service.
     *
     * @param  string  $service
     * @return void
     */
    protected function loadDeferredProvider($service)
    {
        $provider = $this->deferredServices[$service];

        if (!isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }

    /**
     * Register a deffered provider and service.
     *
     * @param  string  $provider
     * @param  string  $service
     * @return void
     */
    public function registerDeferredProvider($provider, $service = null)
    {
        if ($service) unset($this->deferredServices[$service]);

        $this->register($instance = new $provider($this));
    }

    /**
     * Resolve the given type from the Container.
     *
     * (Overriding Container::make)
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, $parameters = array())
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->deferredServices[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }

        return parent::make($abstract, $parameters);
    }

    /**
     * Run the application and send the response.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @return void
     */
    public function run(SymfonyRequest $request = null)
    {
        $request = $request ?: $this['request'];

        $this->dispatch($request);
    }

    /**
     * Handle the given request and get the response.
     *
     * @param  Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function dispatch(Request $request)
    {
        $router = $this['router'];

        return $router->dispatch($this->prepareRequest($request));
    }

    /**
     * Prepare the request by injecting any services.
     *
     * @param  Http\Request  $request
     * @return \Http\Request
     */
    public function prepareRequest(Request $request)
    {
        $config = $this['config'];

        if (! is_null($config['session.driver']) && ! $request->hasSession()) {
            $session = $this['session.store'];

            $request->setSession($session);
        }

        return $request;
    }

    /**
     * Set the application's deferred services.
     *
     * @param  array  $services
     * @return void
     */
    public function setDeferredServices(array $services)
    {
        $this->deferredServices = $services;
    }

    /**
     * Get the Service Provider Repository instance.
     *
     * @return \Core\Providers
     */
    public function getProviderRepository()
    {
        $path = APPDIR .'Storage';

        return new ProviderRepository($path);
    }

    /**
     * Get the Application URL.
     *
     * @param  string  $path
     * @return string
     */
    public function url($path = '')
    {
        return site_url($path);
    }
}
