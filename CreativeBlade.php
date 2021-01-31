<?php

namespace CreativeBlade;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use CreativeBlade\Compilers\BladeCompiler;
use CreativeBlade\Engines\CompilerEngine;
use CreativeBlade\Engines\EngineResolver;
use CreativeBlade\Engines\FileEngine;
use CreativeBlade\Engines\PhpEngine;

class CreativeBlade
{
	/**
	 * Array containing paths where to look for blade files
	 * @var array
	 */
	protected $viewPaths;
	
	/**
	 * Location where to store cached views
	 * @var string
	 */
	protected $cachePath;
	
	/**
	 * Illuminate Container instance.
	 * @var \Illuminate\Container\Container
	 */
	protected $app;
	protected $metaData;
	
	/**
	 * Register the service provider.
	 *
	 * @param $viewPaths
	 * @param $cachePath
	 * @param array $metaData
	 */
	
	public function __construct($viewPaths, $cachePath, $metaData = [])
	{
		$this->app = new Container();
		$this->viewPaths = (array)$viewPaths;
		$this->cachePath = $cachePath;
		$this->metaData = $metaData;
		$this->register();
	}
	
	public function register()
	{
		
		$this->registerFilesystem();
		$this->registerEvents();
		
		$this->registerFactory();
		$this->registerViewFinder();
		$this->registerBladeCompiler();
		$this->registerEngineResolver();
	}
	
	public function view()
	{
		return $this->app['view'];
	}
	
	public function registerFilesystem()
	{
		$this->app->bind('files', function()
		{
			return new Filesystem;
		});
	}
	
	public function registerEvents()
	{
		$this->app->bind('events', function()
		{
			return new Dispatcher;
		});
	}
	
	/**
	 * Register the view environment.
	 *
	 * @return void
	 */
	public function registerFactory()
	{
		$this->app->singleton('view', function($app)
		{
			// Next we need to grab the engine resolver instance that will be used by the
			// environment. The resolver will be used by an environment to get each of
			// the various engine implementations such as plain PHP or Blade engine.
			$resolver = $app['view.engine.resolver'];
			
			$finder = $app['view.finder'];
			
			$factory = $this->createFactory($resolver, $finder, $app['events']);
			
			// We will also set the container instance on this view environment since the
			// view composers may be classes registered in the container, which allows
			// for great testable, flexible composers for the application developer.
			$factory->setContainer($app);
			
			$factory->share('app', $app);
			
			return $factory;
		});
	}
	
	/**
	 * Create a new Factory Instance.
	 *
	 * @param \CreativeBlade\Engines\EngineResolver $resolver
	 * @param \CreativeBlade\ViewFinderInterface $finder
	 * @param \Illuminate\Contracts\Events\Dispatcher $events
	 * @return \CreativeBlade\Factory
	 */
	protected function createFactory($resolver, $finder, $events)
	{
		return new Factory($resolver, $finder, $events);
	}
	
	/**
	 * Register the view finder implementation.
	 *
	 * @return void
	 */
	public function registerViewFinder()
	{
		$me = $this;
		$this->app->bind('view.finder', function($app) use ($me)
		{
			return new FileViewFinder($app['files'], $me->viewPaths);
		});
	}
	
	/**
	 * Register the Blade compiler implementation.
	 *
	 * @return void
	 */
	public function registerBladeCompiler()
	{
		$this->app->singleton('blade.compiler', function($app)
		{
			return tap(new BladeCompiler($app['files'], $app['config']['view.compiled']), function($blade)
			{
				$blade->component('dynamic-component', DynamicComponent::class);
			});
		});
	}
	
	/**
	 * Register the engine resolver instance.
	 *
	 * @return void
	 */
	public function registerEngineResolver()
	{
		$this->app->singleton('view.engine.resolver', function()
		{
			$resolver = new EngineResolver;
			
			// Next, we will register the various view engines with the resolver so that the
			// environment will resolve the engines needed for various views based on the
			// extension of view file. We call a method for each of the view's engines.
			foreach(['file', 'php', 'blade'] as $engine)
			{
				$this->{'register' . ucfirst($engine) . 'Engine'}($resolver);
			}
			
			return $resolver;
		});
	}
	
	/**
	 * Register the file engine implementation.
	 *
	 * @param \CreativeBlade\Engines\EngineResolver $resolver
	 * @return void
	 */
	public function registerFileEngine($resolver)
	{
		$resolver->register('file', function()
		{
			return new FileEngine($this->app['files']);
		});
	}
	
	/**
	 * Register the PHP engine implementation.
	 *
	 * @param \CreativeBlade\Engines\EngineResolver $resolver
	 * @return void
	 */
	public function registerPhpEngine($resolver)
	{
		$resolver->register('php', function()
		{
			return new PhpEngine($this->app['files']);
		});
	}
	
	/**
	 * Register the Blade engine implementation.
	 *
	 * @param \CreativeBlade\Engines\EngineResolver $resolver
	 * @return void
	 */
	public function registerBladeEngine($resolver)
	{
		$me = $this;
		// The Compiler engine requires an instance of the CompilerInterface, which in
		// this case will be the Blade compiler, so we'll first create the compiler
		// instance to pass into the engine so it can compile the views properly.
		$this->app->singleton('blade.compiler', function() use ($me)
		{
			return new BladeCompiler(
				$this->app['files'], $me->cachePath
			);
		});
		
		$resolver->register('blade', function()
		{
			return new CompilerEngine($this->app['blade.compiler'], $this->metaData, $this->app['files']);
		});
	}
}
