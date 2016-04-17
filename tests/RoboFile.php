<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * Download robo.phar from http://robo.li/robo.phar and type in the root of the repo: $ php robo.phar
 * Or do: $ composer update, and afterwards you will be able to execute robo like $ php vendor/bin/robo
 *
 * @copyright   Copyright (C) 2008 - 2016 redCOMPONENT.com. All rights reserved.
 * @license     GNU General Public License version 2 or later, see LICENSE.
 *
 * @see  http://robo.li/
 */
require_once 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/**
 * Class RoboFile
 *
 * @since  1.6.14
 */
class RoboFile extends \Robo\Tasks
{
	// Load tasks from composer, see composer.json
	use \redcomponent\robo\loadTasks;

	/**
	 * Current root folder
	 */
	private $testsFolder = './';

	/**
	 * Hello World example task.
	 *
	 * @see  https://github.com/redCOMPONENT-COM/robo/blob/master/src/HelloWorld.php
	 * @link https://packagist.org/packages/redcomponent/robo
	 *
	 * @return object Result
	 */
	public function sayHelloWorld()
	{
		$result = $this->taskHelloWorld()->run();

		return $result;
	}

	/**
	 * Sends Codeception errors to Slack
	 *
	 * @param   string  $slackChannel             The Slack Channel ID
	 * @param   string  $slackToken               Your Slack authentication token.
	 * @param   string  $codeceptionOutputFolder  Optional. By default tests/_output
	 *
	 * @return mixed
	 */
	public function sendCodeceptionOutputToSlack($slackChannel, $slackToken = null, $codeceptionOutputFolder = null)
	{
		if (is_null($slackToken))
		{
			$this->say('we are in Travis environment, getting token from ENV');

			// Remind to set the token in repo Travis settings,
			// see: http://docs.travis-ci.com/user/environment-variables/#Using-Settings
			$slackToken = getenv('SLACK_ENCRYPTED_TOKEN');
		}

		if (is_null($codeceptionOutputFolder))
		{
			$this->codeceptionOutputFolder = '_output';
		}

		$this->say($codeceptionOutputFolder);

		$result = $this
			->taskSendCodeceptionOutputToSlack(
				$slackChannel,
				$slackToken,
				$codeceptionOutputFolder
			)
			->run();

		return $result;
	}

	/**
	 * Downloads and prepares a Joomla CMS site for testing
	 *
	 * @return mixed
	 */
	public function prepareSiteForSystemTests()
	{
		// Get Joomla Clean Testing sites
		if (is_dir('joomla-cms3'))
		{
			$this->taskDeleteDir('joomla-cms3')->run();
		}

		$this->cloneJoomla();
	}

	/**
	 * Downloads and prepares a Joomla CMS site for testing
	 *
	 * @return mixed
	 */
	public function prepareSiteForUnitTests()
	{
		// Make sure we have joomla
		if (!is_dir('joomla-cms3'))
		{
			$this->cloneJoomla();
		}

		if (!is_dir('joomla-cms3/libraries/vendor/phpunit'))
		{
			$this->getComposer();
			$this->taskComposerInstall('../composer.phar')->dir('joomla-cms3')->run();
		}

		// Copy extension. No need to install, as we don't use mysql db for unit tests
		$joomlaPath = __DIR__ . '/joomla-cms3';
		$this->_exec("gulp copy --wwwDir=$joomlaPath --gulpfile ../build/gulpfile.js");
	}

	/**
	 * Executes Selenium System Tests in your machine
	 *
	 * @param   array  $opts  Use -h to see available options
	 *
	 * @return mixed
	 */
	public function runTest($opts = [
		'test|t'	    => null,
		'suite|s'	    => 'acceptance'])
	{
		$this->getComposer();

		$this->taskComposerInstall()->run();

		if (isset($opts['suite']) && 'api' === $opts['suite'])
		{
			// Do not launch selenium when running API tests
		}
		else
		{
			$this->runSelenium();

			$this->taskWaitForSeleniumStandaloneServer()
				->run()
				->stopOnFail();
		}

		// Make sure to Run the Build Command to Generate AcceptanceTester
		$this->_exec("vendor/bin/codecept build");

		if (!$opts['test'])
		{
			$this->say('Available tests in the system:');

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
						$this->testsFolder . $opts['suite'],
						RecursiveDirectoryIterator::SKIP_DOTS
					),
						RecursiveIteratorIterator::SELF_FIRST
				);

			$tests = array();

			$iterator->rewind();
			$i = 1;

			while ($iterator->valid())
			{
				if (strripos($iterator->getSubPathName(), 'cept.php')
					|| strripos($iterator->getSubPathName(), 'cest.php'))
				{
					$this->say('[' . $i . '] ' . $iterator->getSubPathName());
					$tests[$i] = $iterator->getSubPathName();
					$i++;
				}

				$iterator->next();
			}

			$this->say('');
			$testNumber	= $this->ask('Type the number of the test  in the list that you want to run...');
			$opts['test'] = $tests[$testNumber];
		}

		$pathToTestFile = './' . $opts['suite'] . '/' . $opts['test'];

		// Loading the class to display the methods in the class
		require './' . $opts['suite'] . '/' . $opts['test'];

		$classes = Nette\Reflection\AnnotationsParser::parsePhp(file_get_contents($pathToTestFile));
		$className = array_keys($classes)[0];

		// If test is Cest, give the option to execute individual methods
		if (strripos($className, 'cest'))
		{
			$testFile = new Nette\Reflection\ClassType($className);
			$testMethods = $testFile->getMethods(ReflectionMethod::IS_PUBLIC);

			foreach ($testMethods as $key => $method)
			{
				$this->say('[' . $key . '] ' . $method->name);
			}

			$this->say('');
			$methodNumber = $this->askDefault('Choose the method in the test to run (hit ENTER for All)', 'All');

			if ($methodNumber != 'All')
			{
				$method = $testMethods[$methodNumber]->name;
				$pathToTestFile = $pathToTestFile . ':' . $method;
			}
		}

		$this->taskCodecept()
			->test($pathToTestFile)
			->arg('--steps')
			->arg('--debug')
			->arg('--fail-fast')
			->run()
			->stopOnFail();

		if (!'api' == $opts['suite'])
		{
			$this->killSelenium();
		}
	}

	/**
	 * Preparation for running manual tests after installing Joomla/Extension and some basic configuration
	 *
	 * @return void
	 */
	public function runTestPreparation()
	{
		$this->prepareSiteForSystemTests();

		$this->getComposer();

		$this->taskComposerInstall()->run();

		$this->runSelenium();

		$this->taskWaitForSeleniumStandaloneServer()
			->run()
			->stopOnFail();

		// Make sure to Run the Build Command to Generate AcceptanceTester
		$this->_exec("vendor/bin/codecept build");

		$this->taskCodecept()
			->arg('--steps')
			->arg('--debug')
			->arg('--tap')
			->arg('--fail-fast')
			->arg($this->testsFolder . 'acceptance/install/')
			->run()
			->stopOnFail();
	}

	/**
	 * Function to Run tests in a Group
	 *
	 * @param   boool  $excludePreparation  Exclude preparation, including selenium scripts
	 *
	 * @return void
	 */
	public function runTests($excludePreparation = false)
	{
		if (!$excludePreparation)
		{
			$this->prepareSiteForSystemTests();

			$this->prepareReleasePackages();

			$this->getComposer();

			$this->taskComposerInstall()->run();

			$this->runSelenium();
		}

		$this->taskWaitForSeleniumStandaloneServer()
			->run()
			->stopOnFail();

		// Make sure to Run the Build Command to Generate AcceptanceTester
		$this->_exec("vendor/bin/codecept build");

		$this->taskCodecept()
			 ->arg('--steps')
			 ->arg('--debug')
			 ->arg('--fail-fast')
			 ->arg($this->testsFolder . 'acceptance/install/')
			 ->run()
			 ->stopOnFail();

		$this->taskCodecept()
			 ->arg('--steps')
			 ->arg('--debug')
			 ->arg('--fail-fast')
			 ->arg($this->testsFolder . 'acceptance/administrator/')
			 ->run()
			 ->stopOnFail();

		$this->taskCodecept()
			 ->arg('--steps')
			 ->arg('--debug')
			 ->arg('--fail-fast')
			 ->arg('api')
			 ->run()
			 ->stopOnFail();

		$this->taskCodecept()
			 ->arg('--steps')
			 ->arg('--debug')
			 ->arg('--fail-fast')
			 ->arg($this->testsFolder . 'acceptance/uninstall/')
			 ->run()
			 ->stopOnFail();

		$this->killSelenium();
	}

	/**
	 * Function to run unit tests
	 *
	 * @return void
	 */
	public function runUnitTests()
	{
		$this->prepareSiteForUnitTests();
		$this->_exec("joomla-cms3/libraries/vendor/phpunit/phpunit/phpunit")
			->stopOnFail();
	}

	/**
	 * Stops Selenium Standalone Server
	 *
	 * @return void
	 */
	public function killSelenium()
	{
		$this->_exec('curl http://localhost:4444/selenium-server/driver/?cmd=shutDownSeleniumServer');
	}

	/**
	 * Downloads Composer
	 *
	 * @return void
	 */
	private function getComposer()
	{
		// Make sure we have Composer
		if (!file_exists('./composer.phar'))
		{
			$this->_exec('curl --retry 3 --retry-delay 5 -sS https://getcomposer.org/installer | php');
		}
	}

	/**
	 * Runs Selenium Standalone Server.
	 *
	 * @return void
	 */
	public function runSelenium()
	{
		$this->_exec("vendor/bin/selenium-server-standalone >> selenium.log 2>&1 &");
	}

	/**
	 * Prepares the .zip packages of the extension to be installed in Joomla
	 */
	public function prepareReleasePackages()
	{
		$this->_exec("gulp release --skip-version --gulpfile ../build/gulpfile.js");
	}

	/**
	 * Looks for PHP Parse errors in core
	 */
	public function checkForParseErrors()
	{
		$this->_exec(
			'php checkers/phppec.php ../extensions/components/com_redcore/ ../extensions/libraries/ ../extensions/modules/ ../extensions/plugins/'
		);
	}

	/**
	 * Looks for missed debug code like var_dump or console.log
	 */
	public function checkForMissedDebugCode()
	{
		$this->_exec(
			'php checkers/misseddebugcodechecker.php ../extensions/components/com_redcore/ ../extensions/libraries/ ../extensions/modules/ ../extensions/plugins/'
		);
	}

	/**
	 * Check the code style of the project against a passed sniffers
	 */
	public function checkCodestyle()
	{
		if (!is_dir('checkers/phpcs/Joomla'))
		{
			$this->say('Downloading Joomla Coding Standards Sniffers');
			$this->_exec("git clone -b master --single-branch --depth 1 https://github.com/joomla/coding-standards.git checkers/phpcs/Joomla");
		}

		$this->taskExec('php checkers/phpcs.php')
				->printed(true)
				->run();
	}

	/**
	 * Clone joomla from official repo
	 *
	 * @return void
	 */
	private function cloneJoomla()
	{
		$version = 'staging';

		/*
		 * When joomla Staging branch has a bug you can uncomment the following line as a tmp fix for the tests layer.
		 * Use as $version value the latest tagged stable version at: https://github.com/joomla/joomla-cms/releases
		 */
		$version = '3.4.8';

		$this->_exec("git clone -b $version --single-branch --depth 1 https://github.com/joomla/joomla-cms.git joomla-cms3");

		$this->say("Joomla CMS ($version) site created at joomla-cms3/");
	}

	/**
	 * Function to Run all Docker Envorionment Testing
	 *
	 * @return void
	 */
	public function runDockerTests()
	{
		$dockerConfig = file_exists('docker/dockertests.yml') ? Yaml::parse(file_get_contents('docker/dockertests.yml')) : [];

		// Checks if config exists and if there are php versions and Joomla versions to test
		if (!$dockerConfig
			|| !count($dockerConfig)
			|| !isset($dockerConfig['appname']) || $dockerConfig['appname'] == ''
			|| !isset($dockerConfig['phpversions']) || !count($dockerConfig['phpversions'])
			|| !isset($dockerConfig['joomlaversions']) || !count($dockerConfig['joomlaversions']))
		{
			echo "Invalid or unexisting docker config file" . chr(10);

			exit;
		}

		$baseDir = getcwd();

		// Array for storing containers
		$dockerContainer = array();

		// Base app name in lowercase to prevent problems in docker image and container names
		$appNameOrig = $dockerConfig['appname'];
		$appName = strtolower($appNameOrig);

		// Docker variables to replace in all files using variables
		$dockerVariables = array(
			'app:name',
			'php:version',
			'joomla:version',
			'php:version-name',
			'joomla:version-name',
			'php:version-clean',
			'joomla:version-clean',
			'github:token',
			'slack:token',
			'slack:channel'
		);

		// Initial Docker values to replace for variables (it will be populated as needed in the upcoming loops)
		$dockerValues = array(
			'app:name' => $appName
		);

		$dockerValues['github:token'] = isset($dockerConfig['github-token'])
			? $dockerConfig['github-token']
			: (getenv('GITHUB_TOKEN') ? getenv('GITHUB_TOKEN') : '');
		$dockerValues['slack:token'] = isset($dockerConfig['slack-token'])
			? $dockerConfig['slack-token']
			: (getenv('SLACK_TOKEN') ? getenv('SLACK_TOKEN') : '');
		$dockerValues['slack:channel'] = isset($dockerConfig['slack-channel'])
			? $dockerConfig['slack-channel']
			: (getenv('SLACK_CHANNEL') ? getenv('SLACK_CHANNEL') : '');

		// Tries to delete the _dockerfiles working folder if it exists, and re-creates it
		try
		{
			$this->_exec('rm -r "_dockerfiles"');
		}
		catch (Exception $e)
		{
		}

		$this->taskFileSystemStack()
			->mkdir('_dockerfiles')
			->run();

		// Clones Joomla under _dockerfiles/cms for each version to test according to yml file
		foreach ($dockerConfig['joomlaversions'] as $joomlaVersion)
		{
			$this->taskGitStack()
				->cloneRepo('-b ' . $joomlaVersion . ' --single-branch --depth 1 https://github.com/joomla/joomla-cms.git', '_dockerfiles/cms/' . $joomlaVersion)
				->run();
		}

		// Makes installer available to the containers
		$this->_exec('mkdir "_dockerfiles/cms/' . $appName . '"');
		$this->_exec($this->getOSCommand('cp -f') . ' releases/' . $appNameOrig . '.zip _dockerfiles/cms/' . $appName . '/' . $appNameOrig . '.zip');

		// Zips Joomla
		chdir('_dockerfiles/cms');
		$this->createZip('.', '../.cms.zip', $exclude = array('./staging/.git'));
		chdir($baseDir);
		/*$this->taskFileSystemStack()
			->rename('_dockerfiles/cms/.cms.zip', '_dockerfiles/.cms.zip')
			->run();*/

		// Zips Test scripts
		chdir($baseDir . '/../');
		$this->createZip('tests', 'tests/_dockerfiles/.tests.zip', $exclude = array('tests/_dockerfiles'));

		// Going back to tests directory
		chdir($baseDir);

		// DB container run (trying to stop it first in case it already exists)
		try
		{
			$this->taskDockerStop('db')->run();
			$this->taskDockerRemove('db')->run();
		}
		catch (Exception $e)
		{
		}

		$dockerContainer['db'] = $this->taskDockerRun('mysql')
			->detached()
			->env('MYSQL_ROOT_PASSWORD', 'root')
			->name('db')
			->publish(13306, 3306)
			->run();

		// Pulls the latest version of the joomla-test-client image
		$this->taskDockerPull('jatitoam/joomla-test-client:firefox')
			->run();

		$i = 0;

		// Actions per php version to test
		foreach ($dockerConfig['phpversions'] as $phpVersion)
		{
			$dockerValues['php:version'] = $phpVersion;
			$phpVersionName = str_replace('.', '-', $phpVersion);
			$phpVersionClean = str_replace('.', '', $phpVersion);
			$dockerValues['php:version-name'] = $phpVersionName;
			$dockerValues['php:version-clean'] = $phpVersionClean;

			// Creates the base php folder to build the server (app-layer) container.  Also copies base Dockerfile replacing variables
			$this->taskFileSystemStack()
				->mkdir('_dockerfiles/php/' . $phpVersion)
				->copy('docker/Dockerfile-server', '_dockerfiles/php/' . $phpVersion . '/Dockerfile')
				->run();
			$this->replaceVariablesInFile('_dockerfiles/php/' . $phpVersion . '/Dockerfile', $dockerVariables, $dockerValues);

			// Unzips Joomla installs to make them available to the containers
			$this->extractZip('_dockerfiles/.cms.zip', '_dockerfiles/php/' . $phpVersion . '/joomla');

			// Tries to stop the container in case one with the same name already exists
			try
			{
				$this->taskDockerStop($appName . '-test-server-' . $phpVersionClean)->run();
				$this->taskDockerRemove($appName . '-test-server-' . $phpVersionClean)->run();
			}
			catch (Exception $e)
			{
			}

			// Tries to delete the image to be created, in case it already exists
			try
			{
				$this->_exec('docker rmi ' . $appName . '-test-server:' . $phpVersion);
			}
			catch (Exception $e)
			{
			}

			// Pulls the latest version of the joomla-test-server image for this php version
			$this->taskDockerPull('jatitoam/joomla-test-server:' . $phpVersion)
				->run();

			// Builds the specific php-version container of the joomla-test-server Docker image
			$this->taskDockerBuild('_dockerfiles/php/' . $phpVersion)
				->tag($appName . '-test-server:' . $phpVersion)
				->run();

			// Executes the app-layer container for this php version
			// @toDO: Allow multiple versions of the app, for now it's "unique" version
			$dockerContainer['php-' . $phpVersion] = $this->taskDockerRun($appName . '-test-server:' . $phpVersion)
				->detached()
				->env('JOOMLA_TEST_APP_NAME', $appName)
				->env('JOOMLA_TEST_JOOMLA_VERSIONS', implode(',', $dockerConfig['joomlaversions']))
				->env('JOOMLA_TEST_APP_VERSIONS', 'unique')
				->name($appName . '-test-server-' . $phpVersionClean)
				->publish(8000 + (int) $phpVersionClean, 80)
				->link($dockerContainer['db'], 'mysql')
				->run();

			// Client-actions related to the specific Joomla version
			foreach ($dockerConfig['joomlaversions'] as $joomlaVersion)
			{
				$dockerValues['joomla:version'] = $joomlaVersion;
				$joomlaVersionName = str_replace('.', '-', $joomlaVersion);
				$joomlaVersionClean = str_replace('.', '', $joomlaVersion);
				$dockerValues['joomla:version-name'] = $joomlaVersionName;
				$dockerValues['joomla:version-clean'] = $joomlaVersionClean;

				// PHP-Joomla version client container folder with app installer and container configuration files
				$this->taskFileSystemStack()
					->mkdir('_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName)
					->copy('docker/Dockerfile-client', '_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/Dockerfile')
					->copy('docker/docker-entrypoint-specific.sh', '_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/entrypoint-specific.sh')
					->run();
				$this->replaceVariablesInFile('_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/Dockerfile', $dockerVariables, $dockerValues);
				$this->replaceVariablesInFile(
					'_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/entrypoint-specific.sh', $dockerVariables, $dockerValues
				);

				// Unzips app tests files/folders in the container files
				$this->extractZip('_dockerfiles/.tests.zip', '_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName);

				// Codeception configuration files with variable replacement
				$this->taskFileSystemStack()
					->copy(
						'docker/acceptance.suite.dist.yml', '_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/acceptance.suite.yml'
					)
					->copy('docker/api.suite.dist.yml', '_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/api.suite.yml')
					->run();
				$this->replaceVariablesInFile(
					'_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/api.suite.yml', $dockerVariables, $dockerValues
				);
				$this->replaceVariablesInFile(
					'_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/acceptance.suite.yml', $dockerVariables, $dockerValues
				);

				// Tries to stop the container in case one with the same name already exists
				try
				{
					$this->taskDockerStop($appName . '-test-client-' . $phpVersionClean . '-' . $joomlaVersionClean)->run();
					$this->taskDockerRemove($appName . '-test-client-' . $phpVersionClean . '-' . $joomlaVersionClean)->run();
				}
				catch (Exception $e)
				{
				}

				// Tries to delete the image to be created, in case it already exists
				try
				{
					$this->_exec('docker rmi ' . $appName . '-test-client:' . $phpVersion . '-' . $joomlaVersion);
				}
				catch (Exception $e)
				{
				}

				// Builds the specific php/joomla-version container of the joomla-test-client Docker image
				$this->taskDockerBuild('_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion)
					->tag($appName . '-test-client:' . $phpVersion . '-' . $joomlaVersion)
					->run();

				// Executes the client-layer container for this php/joomla version
				// @toDO: Allow multiple versions of the app, for now it's "unique" version
				$dockerContainer['client-' . $phpVersion . '-' . $joomlaVersion] = $this->taskDockerRun(
						$appName . '-test-client:' . $phpVersion . '-' . $joomlaVersion
					)
					// ->detached()
					->name($appName . '-test-client-' . $phpVersionClean . '-' . $joomlaVersionClean)
					->publish(5900 + $i, 5900)
					->link($dockerContainer['php-' . $phpVersion], $appName . '-test-server-' . $phpVersionClean)
					->run();

				$i ++;
			}
		}
	}

	/**
	 * Function to extract zip file to a specific location
	 *
	 * @param   string  $source  Name of the file (path)
	 * @param   string  $path    Extract to
	 *
	 * @return bool
	 */
	protected function extractZip($source, $path)
	{
		$zip = new ZipArchive;
		$resource = $zip->open($source);

		if ($resource === true)
		{
			$zip->extractTo($path);
			$zip->close();

			return true;
		}

		return false;
	}

	/**
	 * Function to extract zip file to a specific location
	 *
	 * @param   string  $source   Name of the folder (path)
	 * @param   string  $path     Compress to file
	 * @param   array   $exclude  Zip folder or file exclusion
	 *
	 * @return bool
	 */
	protected function createZip($source, $path, $exclude = array())
	{
		$zip = new ZipArchive;
		$resource = $zip->open($path, ZIPARCHIVE::CREATE | ZipArchive::OVERWRITE);

		if ($resource === true)
		{
			$this->addZipFiles(
				$zip,
				$source, // The path to the folder you wish to archive
				$exclude, // Exclude any folder or file
				strlen($source) + 1 // The string length of the base folder
			);

			$zip->close();

			$this->say('Created zip to path: ' . realpath($path));

			return true;
		}

		return false;
	}

	/**
	 * Function to add zip files to a specific location
	 *
	 * @param   ZipArchive  &$zip     Zip Archive
	 * @param   string      $dir      Directory to check
	 * @param   array       $exclude  Exclude files or folders
	 * @param   int         $base     Base length
	 *
	 * @return void
	 */
	protected function addZipFiles(&$zip, $dir, $exclude = array(), $base = 0)
	{
		foreach (glob($dir . '/*') as $file)
		{
			if (in_array($file, $exclude))
			{
				continue;
			}

			if (is_dir($file))
			{
				$this->addZipFiles($zip, $file, $exclude, $base);
			}
			else
			{
				$zip->addFile($file, substr($file, $base));
			}
		}
	}

	/**
	 * Function to replace Docker variables in a given file
	 *
	 * @param   string  $fileName   Name of the file (path)
	 * @param   array   $variables  Variables to replace in file
	 * @param   array   $values     Values to replace
	 *
	 * @return void
	 */
	protected function replaceVariablesInFile($fileName, $variables, $values)
	{
		foreach ($variables as $variable)
		{
			if (isset($values[$variable]))
			{
				$this->taskReplaceInFile($fileName)
					->from('{' . $variable . '}')
					->to($values[$variable])
					->run();
			}
		}
	}

	/**
	 * Function to Run tests from Docker Container
	 *
	 * @return void
	 */
	public function runTestsFromDockerContainer()
	{
		$this->runTests(true);
	}

	/**
	 * Function to replace command depending on the used OS
	 *
	 * @param   string  $command  Name of the command
	 * @param   string  $os       Operating system
	 *
	 * @return string
	 */
	protected function getOSCommand($command, $os = 'windows')
	{
		switch ($command)
		{
			/*case 'cp':
				return $os == 'windows' ? 'copy' : 'cp';

			case 'cp -f':
				return $os == 'windows' ? 'copy' : 'cp -f';*/

			default :
				return $command;
		}
	}
}
