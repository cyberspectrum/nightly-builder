<?php

namespace CyberSpectrum\Command;

use Composer\Autoload\ClassMapGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class BuildCommand extends Command
{
	/**
	 * @var InputInterface
	 */
	protected $input;

	/**
	 * @var OutputInterface
	 */
	protected $output;

	protected $repository;

	protected $package;

	protected $config;

	protected $encodedName;

	protected $nightlyModule;

	protected $runonce = array();

	protected $classmap = array();

	/**
	 * @var FileSystem $fs
	 */
	protected $fs;

	const OPENER = '
/**
 * THIS BLOCK HAS BEEN ADDED BY THE NIGHTLY PACKAGE BUILDER.
 * DO NOT REMOVE!
 */
';

	const CLOSER = '
/**
 * END OF NIGHTLY PACKAGE BUILDER ADDED BLOCK.
 */
';

	protected function configure()
	{
		$this
			->setName('build')
			->setDescription('Create packages from a composer designed repository including all dependencies for nightly distribution including custom auto loading etc.')
			->addOption('zip', 'Z', InputOption::VALUE_NONE, 'Create a zip archive (enabled by default, only present for sanity).')
			->addOption('dir', 'D', InputOption::VALUE_NONE, 'Create a directory instead of an archive.')
			->addOption('xml', 'x', InputOption::VALUE_OPTIONAL, 'Create a xml file at the given location.')
			->addArgument('project', InputArgument::OPTIONAL, 'The input path containing the composer.json.', 'nightly.composer.json')
			->addArgument('output', InputArgument::OPTIONAL, 'The output path.', 'package.zip');
	}

	/**
	 * @param Process $process
	 *
	 * @throws \RuntimeException
	 */
	protected function runProcess($process)
	{
		$output = $this->output;
		/** @noinspection PhpUnusedParameterInspection */
		$writethru = function ($type, $buffer) use ($output) {
			$output->write($buffer);
		};

		$process->setTimeout(120);
		$process->run($writethru);
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
	}

	/**
	 * Get a copy of composer and install in workspace if not present already.
	 */
	protected function getComposer()
	{
		if (!file_exists($this->repository . '/composer.phar')) {
			$this->output->writeln('  - <info>Install local copy of composer</info>');
			$process = new Process('curl -sS https://getcomposer.org/installer | php', $this->repository);
			$this->runProcess($process);
		}
	}

	/**
	 * Prepare the workspace.
	 *
	 * @throws \RuntimeException
	 */
	protected function prepare()
	{
		$root         = getcwd();
		$this->config = json_decode(file_get_contents($this->input->getArgument('project')), true);

		if (!(isset($this->config['name']) && isset($this->config['require']))) {
			throw new \RuntimeException('Project ' . $this->input->getArgument('project') . ' does not seems to be a composer project.');
		}

		$this->encodedName   = str_replace('/', '-', $this->config['name']);
		$this->nightlyModule = 'system/modules/!' . $this->encodedName;

		$this->repository = $root . DIRECTORY_SEPARATOR . 'build_repository_' . $this->encodedName;
		if (!is_dir($this->repository))
			mkdir($this->repository);

		$this->package = $root . DIRECTORY_SEPARATOR . 'build_package_' . $this->encodedName;

		$this->fs->remove($this->package);
		$this->output->writeln('  - <info>Preparing clean output environment ' . $this->package . '</info>');

		if (!is_dir($this->package))
		{
			mkdir($this->package);
		}

		$this->output->writeln('  - <info>Prepare project ' . $this->repository . DIRECTORY_SEPARATOR . 'composer.json' . '</info>');
		copy($this->input->getArgument('project'), $this->repository . DIRECTORY_SEPARATOR . 'composer.json');

		$this->output->writeln('  - <info>Validate project</info>');
		if (!file_exists($this->repository . '/composer.json')) {
			throw new \RuntimeException('Project ' . $this->input->getArgument('project') . ' does not seems to be a composer project.');
		}

	}

	/**
	 * Perform the composer install/update to get the packages in the current version.
	 */
	protected function doInstall()
	{
		$this->output->writeln('  - <info>Install dependencies</info>');
		// $process = new Process('php ' . escapeshellarg($this->repository . '/composer.phar') . ' install --no-dev', $this->repository);
		$process = new Process('php ' . escapeshellarg($this->repository . '/composer.phar') . ' update --no-dev', $this->repository);
		$this->runProcess($process);
	}

	/**
	 * Determine if a package is blacklisted and therefore shall not get exported.
	 *
	 * @param string $package The package name as stated in composer.json (vendor/name).
	 *
	 * @return bool
	 */
	protected function isBlackListedPackage($package)
	{
		$blacklist = array('contao/core', 'contao-community-alliance/composer-installer');

		if (array_key_exists('extra', $this->config)
			&& array_key_exists('nightly-builder', $this->config['extra'])
			&& array_key_exists('blacklist', $this->config['extra']['nightly-builder'])) {
			$blacklist = array_merge($blacklist, $this->config['extra']['nightly-builder']['blacklist']);
		}

		return in_array($package, $blacklist);
	}

	/**
	 * Create the autoloader for the nightly build package.
	 *
	 * @param string $modulePath The module path to this nightly package.
	 *
	 * @param array  $classmap   The class map for all classes that are not loaded via auto loader within Contao but via composer.
	 */
	protected function createAutoloader($modulePath, $classmap)
	{
		if (!$classmap)
		{
			return;
		}

		$destPath = $this->package . '/' . $modulePath;

		if (!is_dir($destPath . '/config'))
		{
			mkdir($destPath . '/config', 0777, true);
		}

		if (file_exists($destPath . '/config/autoload.php')) {
			$autoload = file_get_contents($destPath . '/config/autoload.php');
			$autoload = preg_replace('#\?>\s*$#', '', $autoload);
		}
		else {
			$autoload = <<<EOF
<?php

EOF
			;
		}

		$opener = self::OPENER;
		$closer = self::CLOSER;

		$autoload .= <<<EOF
$opener
require_once(TL_ROOT . '/$modulePath/config/vendor_autoload.php');
$closer
EOF
		;
		file_put_contents(
			$destPath . '/config/autoload.php',
			$autoload
		);

		if (file_exists($destPath . '/config/config.php')) {
			$config = file_get_contents($destPath . '/config/config.php');
			$config = preg_replace('#\?>\s*$#', '', $config);
		}
		else {
			$config = <<<EOF
<?php

EOF
			;
		}

		$classmapClasses = array();
		foreach ($classmap as $className => $path) {
			$classmapClasses[] = $className;
		}
		$classmapClasses = array_map(
			function($className) {
				return var_export($className, true);
			},
			$classmapClasses
		);
		$classmapClasses = implode(',' . "\n\t\t", $classmapClasses);

		$config .= <<<EOF
$opener
if (version_compare(VERSION, '3', '<')) {
	spl_autoload_unregister('__autoload');
	require_once(TL_ROOT . '/$modulePath/config/vendor_autoload.php');
	spl_autoload_register('__autoload');

	\$classes = array(
		$classmapClasses
	);
	\$cache = FileCache::getInstance('classes');
	foreach (\$classes as \$class) {
		if (!\$cache->\$class) {
			\$cache->\$class = true;
		}
	}
}
$closer
EOF
		;
		file_put_contents(
			$destPath . '/config/config.php',
			$config
		);

		$classmapTmp = array();
		foreach ($classmap as $class => $file)
		{
			$classmapTmp[$class] = str_replace($this->package, '', $file);
		}

		$classmapExport = var_export($classmapTmp, true);
		$fn             = md5($this->encodedName);

		$function = <<<EOF
<?php
/**
 * This file has been auto generated by the nightly build script and is only needed for nightly builds.
 */

function autoload_$fn(\$className)
{
	\$classes =
$classmapExport;
	if (isset(\$classes[\$className]))
	{
		require_once(TL_ROOT . '/' . \$classes[\$className]);
	}
}

spl_autoload_register('autoload_$fn');
EOF
		;
		file_put_contents(
			$destPath . '/config/vendor_autoload.php',
			$function
		);
	}

	/**
	 * Determine if a string is a prefix of another one.
	 *
	 * @param string $prefix The prefix to test against.
	 *
	 * @param string $long   The long string that shall be tested if it starts with the given prefix.
	 *
	 * @return bool
	 */
	protected function isPrefixOf($prefix, $long)
	{
		//
		return substr($long, 0, strlen($prefix)) == $prefix;
	}

	/**
	 * Determine if at least one string within an array is prefixed with the given prefix.
	 *
	 * @param string $prefix The prefix to test against.
	 *
	 * @param array  $list   The list of strings to test.
	 *
	 * @return bool
	 */
	protected function isPrefixIn($prefix, $list)
	{
		foreach ($list as $long)
		{
			if ($this->isPrefixOf($prefix, $long))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Assemble all classes from a package that have not yet been copied to the module path.
	 * Within the destination path, a new sub directory named "classes" will get created and used as destination.
	 *
	 * @param array  $package    The package information as in composer.lock
	 *
	 * @param string $modulePath The module path to use as destination folder to place classes.
	 *
	 * @param array $blackList   An optional blacklist of filename prefixes that shall not get copied.
	 */
	protected function addAdditionalClasses($package, $modulePath, $blackList = array())
	{
		$classmapGenerator = new ClassMapGenerator();
		$classmap          = array();
		$srcPath           = $this->repository . '/vendor/' . $package['name'];
		$destPath          = $this->package . '/' . $modulePath;

		if (array_key_exists('autoload', $package)) {
			if (array_key_exists('psr-0', $package['autoload'])) {
				foreach ($package['autoload']['psr-0'] as $source) {
					if ($this->isPrefixIn($srcPath . '/' . $source, $blackList))
					{
						continue;
					}
					$this->copy($srcPath . '/' . $source, $destPath . '/classes/' . $source);
					$classmap = array_merge($classmap, $classmapGenerator->createMap($destPath . '/classes/' . $source));
				}
			}
			if (array_key_exists('classmap', $package['autoload'])) {
				foreach ($package['autoload']['classmap'] as $source) {
					if ($this->isPrefixIn($srcPath . '/' . $source, $blackList))
					{
						continue;
					}
					$this->copy($srcPath . '/' . $source, $destPath . '/classes/' . $source);
					$classmap = array_merge($classmap, $classmapGenerator->createMap($destPath . '/classes/' . $source));
				}
			}
		}
		foreach ($classmap as $class => $file)
		{
			$classmap[$class] = str_replace($this->package, '', $file);
		}

		$this->classmap += $classmap;

		return;
	}

	/**
	 * Collect all data for a Contao module and copy it to the appropriate location.
	 *
	 * @param array  $package    The package information as in composer.lock
	 */
	protected function assembleContaoModule($package)
	{
		$symlinks = array();
		$runonce  = array();

		if (array_key_exists('extra', $package) && array_key_exists('contao', $package['extra']))
		{
			if (array_key_exists('symlinks', $package['extra']['contao']))
			{
				$symlinks = $package['extra']['contao']['symlinks'];
			}
			if (array_key_exists('sources', $package['extra']['contao']))
			{
				$symlinks = array_merge($package['extra']['contao']['sources'], $symlinks);
			}

			if (array_key_exists('runonce', $package['extra']['contao'])) {
				$runonce = $package['extra']['contao']['runonce'];
			}
		}

		$blackList = array();
		foreach ($symlinks as $source => $target) {
			$this->copy($this->repository . '/vendor/' . $package['name'] . '/' . $source, $this->package . '/' . $target);
			$blackList[] = $this->repository . '/vendor/' . $package['name'] . '/' . $source;
		}

		$modulePath = 'system/modules/' . str_replace('/', '-', $package['name']);
		// Detect best possible place to put classes into.
		foreach ($symlinks as $target) {
			if (preg_match('#^system/modules/[^/]+#', $target)) {
				$modulePath = $target;
				break;
			}
		}

		$this->addAdditionalClasses($package, $modulePath, $blackList);

		$i = count($this->runonce);
		foreach (array_values($runonce) as $file) {
			$fileName = $this->package . '/' . $this->nightlyModule . '/config/runonce_' . ($i++) . '.php';

			$this->runonce[] = $fileName;
			$this->copy($this->repository . '/vendor/' . $package['name'] .'/' . $file, $fileName);
		}
	}

	/**
	 * Collect all data for a standard composer library and copy it to the appropriate location.
	 *
	 * @param array  $package    The package information as in composer.lock
	 */
	protected function assembleLibrary($package)
	{
		$this->addAdditionalClasses($package, 'system/modules/' . str_replace('/', '-', $package['name']));
	}

	/**
	 * Collect all (non blacklisted) installed packages into the destination path.
	 *
	 * @throws \RuntimeException
	 */
	protected function assemblePackages()
	{
		$config = json_decode(file_get_contents($this->repository . '/composer.lock'), true);
		$this->output->writeln('  - <info>importing: </info>');
		foreach ($config['packages'] as $package)
		{
			if (!$this->isBlackListedPackage($package['name']))
			{
				$this->output->writeln('    - <info>' . $package['name'] . '</info>');

				switch ($package['type'])
				{
					case 'metapackage':
						$this->output->writeln('    - skipped metapackage: <comment>' . $package['name'] . '</comment>');
						break;
					case 'contao-module':
						$this->assembleContaoModule($package);
						break;

					case 'library':
						$this->assembleLibrary($package);
						break;

					default:
						throw new \RuntimeException('Unknown module type encountered: ' . $package['type']);
				}
			}
			else
			{
				$this->output->writeln('    - skipped unneccessary: <comment>' . $package['name'] . '</comment>');
			}
		}
	}

	/**
	 * Save the runonce information into the destination path.
	 *
	 */
	protected function saveConfig()
	{
		if (count($this->runonce)) {
			$dirName = $this->package . '/' . $this->nightlyModule . '/config';
			if (!is_dir($dirName))
			{
				mkdir($dirName, 0777, true);
			}
			$class = 'runonce_' . md5(uniqid('runonce_', true));
			file_put_contents(
				$dirName . '/runonce.php',
				<<<EOF
<?php

class $class extends System
{
	public function __construct()
	{
		parent::__construct();
	}

	public function run()
	{
		for (\$i=0; file_exists(__DIR__ . '/runonce_' . \$i . '.php'); \$i++) {
			try {
				require_once(__DIR__ . '/runonce_' . \$i . '.php');
			}
			catch (\Exception \$e) {
				// first trigger an error to write this into the log file
				trigger_error(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					E_USER_ERROR
				);
				// now log into the system log
				\$this->log(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					'RunonceExecutor run()',
					'ERROR'
				);
			}
		}
	}
}

\$executor = new $class();
\$executor->run();

EOF
			);
		}
	}

	protected function getLicenseUrl($license)
	{
		if (in_array($license, array(
			'AFL-1.1', 'AFL-1.2', 'AFL-2.0', 'AFL-2.1', 'AFL-3.0', 'APL-1.0', 'Aladdin', 'ANTLR-PD', 'Apache-1.0',
			'Apache-1.1', 'Apache-2.0', 'APSL-1.0', 'APSL-1.1', 'APSL-1.2', 'APSL-2.0', 'Artistic-1.0',
			'Artistic-1.0-cl8', 'Artistic-1.0-Perl', 'Artistic-2.0', 'AAL', 'BitTorrent-1.0', 'BitTorrent-1.1',
			'BSL-1.0', 'BSD-2-Clause', 'BSD-2-Clause-FreeBSD', 'BSD-2-Clause-NetBSD', 'BSD-3-Clause',
			'BSD-3-Clause-Clear', 'BSD-4-Clause', 'BSD-4-Clause-UC', 'CECILL-1.0', 'CECILL-1.1', 'CECILL-2.0',
			'CECILL-B', 'CECILL-C', 'ClArtistic', 'CNRI-Python', 'CNRI-Python-GPL-Compatible', 'CPOL-1.02',
			'CDDL-1.0', 'CDDL-1.1', 'CPAL-1.0', 'CPL-1.0', 'CATOSL-1.1', 'Condor-1.1', 'CC-BY-1.0', 'CC-BY-2.0',
			'CC-BY-2.5', 'CC-BY-3.0', 'CC-BY-ND-1.0', 'CC-BY-ND-2.0', 'CC-BY-ND-2.5', 'CC-BY-ND-3.0',
			'CC-BY-NC-1.0', 'CC-BY-NC-2.0', 'CC-BY-NC-2.5', 'CC-BY-NC-3.0', 'CC-BY-NC-ND-1.0', 'CC-BY-NC-ND-2.0',
			'CC-BY-NC-ND-2.5', 'CC-BY-NC-ND-3.0', 'CC-BY-NC-SA-1.0', 'CC-BY-NC-SA-2.0', 'CC-BY-NC-SA-2.5',
			'CC-BY-NC-SA-3.0', 'CC-BY-SA-1.0', 'CC-BY-SA-2.0', 'CC-BY-SA-2.5', 'CC-BY-SA-3.0', 'CC0-1.0',
			'CUA-OPL-1.0', 'D-FSL-1.0', 'WTFPL', 'EPL-1.0', 'eCos-2.0', 'ECL-1.0', 'ECL-2.0', 'EFL-1.0', 'EFL-2.0',
			'Entessa', 'ErlPL-1.1', 'EUDatagrid', 'EUPL-1.0', 'EUPL-1.1', 'Fair', 'Frameworx-1.0', 'FTL',
			'AGPL-1.0', 'AGPL-3.0', 'GFDL-1.1', 'GFDL-1.2', 'GFDL-1.3', 'GPL-1.0', 'GPL-1.0+', 'GPL-2.0',
			'GPL-2.0+', 'GPL-2.0-with-autoconf-exception', 'GPL-2.0-with-bison-exception',
			'GPL-2.0-with-classpath-exception', 'GPL-2.0-with-font-exception', 'GPL-2.0-with-GCC-exception',
			'GPL-3.0', 'GPL-3.0+', 'GPL-3.0-with-autoconf-exception', 'GPL-3.0-with-GCC-exception', 'LGPL-2.1',
			'LGPL-2.1+', 'LGPL-3.0', 'LGPL-3.0+', 'LGPL-2.0', 'LGPL-2.0+', 'gSOAP-1.3b', 'HPND', 'IBM-pibs',
			'IPL-1.0', 'Imlib2', 'IJG', 'Intel', 'IPA', 'ISC', 'JSON', 'LPPL-1.3a', 'LPPL-1.0', 'LPPL-1.1',
			'LPPL-1.2', 'LPPL-1.3c', 'Libpng', 'LPL-1.02', 'LPL-1.0', 'MS-PL', 'MS-RL', 'MirOS', 'MIT', 'Motosoto',
			'MPL-1.0', 'MPL-1.1', 'MPL-2.0', 'MPL-2.0-no-copyleft-exception', 'Multics', 'NASA-1.3', 'Naumen',
			'NBPL-1.0', 'NGPL', 'NOSL', 'NPL-1.0', 'NPL-1.1', 'Nokia', 'NPOSL-3.0', 'NTP', 'OCLC-2.0', 'ODbL-1.0',
			'PDDL-1.0', 'OGTSL', 'OLDAP-2.2.2', 'OLDAP-1.1', 'OLDAP-1.2', 'OLDAP-1.3', 'OLDAP-1.4', 'OLDAP-2.0',
			'OLDAP-2.0.1', 'OLDAP-2.1', 'OLDAP-2.2', 'OLDAP-2.2.1', 'OLDAP-2.3', 'OLDAP-2.4', 'OLDAP-2.5',
			'OLDAP-2.6', 'OLDAP-2.7', 'OPL-1.0', 'OSL-1.0', 'OSL-2.0', 'OSL-2.1', 'OSL-3.0', 'OLDAP-2.8', 'OpenSSL',
			'PHP-3.0', 'PHP-3.01', 'PostgreSQL', 'Python-2.0', 'QPL-1.0', 'RPSL-1.0', 'RPL-1.1', 'RPL-1.5',
			'RHeCos-1.1', 'RSCPL', 'Ruby', 'SAX-PD', 'SGI-B-1.0', 'SGI-B-1.1', 'SGI-B-2.0', 'OFL-1.0', 'OFL-1.1',
			'SimPL-2.0', 'Sleepycat', 'SMLNJ', 'SugarCRM-1.1.3', 'SISSL', 'SISSL-1.2', 'SPL-1.0', 'Watcom-1.0',
			'NCSA', 'VSL-1.0', 'W3C', 'WXwindows', 'Xnet', 'X11', 'XFree86-1.1', 'YPL-1.0', 'YPL-1.1', 'Zimbra-1.3',
			'Zlib', 'ZPL-1.1', 'ZPL-2.0', 'ZPL-2.1', 'Unlicense')))
		{
			return sprintf('http://spdx.org/licenses/%1$s', $license);
		}
		else
		{
			return $license;
		}
	}

	protected function strIsLongerThan($a, $b)
	{
		if (strlen($a) > strlen($b))
		{
			return true;
		}

		return false;
	}

	protected function preparePackageInformation()
	{
		$lock = json_decode(file_get_contents($this->repository . '/composer.lock'), true);

		$data = array
		(
			'maxlen' => array(
				'name'       => '',
				'url'        => '',
				'link'       => '',
				'version'    => '',
				'lastchange' => '',
				'license'    => array
				(
					'name'   => '',
					'url'    => ''
				)
			)
		);

		foreach ($lock['packages'] as $package) {

			if ($this->isBlackListedPackage($package['name']))
			{
				continue;
			}

			if (isset($package['source'])) {
				$url = preg_replace('#\.git$#', '', $package['source']['url']);
			}
			else
			{
				$url = false;
			}

			if (isset($package['homepage'])) {
				$homepage = $package['homepage'];
			}
			else
			{
				$homepage = false;
			}

			$version = $package['version'];
			$time    = $package['time'];

			if (preg_match('#(^dev-|-dev$)#', $package['version'])) {
				if (isset($package['source']['reference'])) {
					$version .= ' @ ' . substr($package['source']['reference'], 0, 6);
				}
				else if (isset($package['dist']['reference'])) {
					$version .= ' @ ' . substr($package['dist']['reference'], 0, 6);
				}
			}

			$license_urls = array();
			foreach ($package['license'] as $license)
			{
				$license_url = $this->getLicenseUrl($license);
				if ($license_url !== $package['license'])
				{
					$license_urls[] = array(
						'name' => $license,
						'url'  => $license_url
					);
				}
				else
				{
					$license_urls[] = array(
						'name' => $license,
						'url'  => ''
					);
				}
			}

			$data[$package['name']] = array(
				'name'       => $package['name'],
				'homepage'   => $homepage,
				'url'        => $url,
				'license'    => $license_urls,
				'version'    => $version,
				'lastchange' => $time
			);

			if ($this->strIsLongerThan($package['name'], $data['maxlen']['name']))
			{
				$data['maxlen']['name'] = $package['name'];
			}
			if ($this->strIsLongerThan($url, $data['maxlen']['url']))
			{
				$data['maxlen']['url'] = $url;
			}

			foreach ($license_urls as $license)
			{
				if ($this->strIsLongerThan($license['name'], $data['maxlen']['license']['name']))
				{
					$data['maxlen']['license']['name'] = $license['name'];
				}
				if ($this->strIsLongerThan($license['url'], $data['maxlen']['license']['url']))
				{
					$data['maxlen']['license']['url'] = $license['url'];
				}
			}

			if ($this->strIsLongerThan($version, $data['maxlen']['version']))
			{
				$data['maxlen']['version'] = $version;
			}
			if ($this->strIsLongerThan($time, $data['maxlen']['lastchange']))
			{
				$data['maxlen']['lastchange'] = $time;
			}
		}

		return $data;
	}

	/**
	 * Create the backend module which displays information about this build.
	 */
	protected function createBackendModule()
	{
		$html = <<<EOF
<table class="table table-striped nightlyinfo">
	<thead>
		<tr>
			<th>Package</th>
			<th>Version</th>
			<th>Time</th>
			<th>License</th>
		</tr>
	</thead>
	<tbody>

EOF;

		foreach ($this->preparePackageInformation() as $name => $package)
		{
			if ($name == 'maxlen')
			{
				continue;
			}

			if (preg_match('#^https?://#', $package['url'])) {
				$link = sprintf('<a href="%1$s" target="_blank">%2$s</a>', $package['url'], $package['name']);
			}
			else {
				$link = $package['name'];
			}

			$version = str_replace(' ', '&nbsp;', $package['version']);

			$time    = $package['lastchange'];

			$license_link = '';
			foreach ($package['license'] as $license)
			{
				if ($license['url']) {
					$license_link .= sprintf('<a href="%1$s" target="_blank">%2$s</a>', $license['url'], $license['name']);
				}
				else
				{
					$license_link .= $license['name'];
				}
			}

			$html .= <<<EOF
		<tr>
			<td class="name">{$link}</td>
			<td class="version">{$version}</td>
			<td class="time">{$time}</td>
			<td class="license">{$license_link}</td>
		</tr>

EOF;
		}
		$html .= <<<EOF
	</tbody>
</table>

EOF;

		$dirName   = $this->package . '/' . $this->nightlyModule;
		$modPath   = $this->nightlyModule;
		$bename    = $this->encodedName;
		$className = 'BackendModule_' . md5($this->encodedName);

		if (!is_dir($dirName))
		{
			mkdir($dirName . '/config', 0777, true);
			mkdir($dirName . '/html', 0777, true);
		}

		file_put_contents(
			$dirName . '/config/config.php',
			<<<EOF
<?php

\$GLOBALS['BE_MOD']['Nightly builds']['$bename'] = array('callback' => '$className');
EOF
		);

		$this->classmap[$className] = $dirName . '/' . $className . '.php';

		file_put_contents(
			$dirName . '/' . $className . '.php',
			<<<EOF
<?php

class $className extends \BackendModule
{
	/**
	 * Display the overview.
	 */
	public function generate()
	{
		\$GLOBALS['TL_CSS'][] = '$modPath/html/style.css';
		return '$html';
	}

	// No-Op to make the class non abstract.
	protected function compile(){}
}
EOF
		);

		file_put_contents(
			$dirName . '/html/.htaccess',
			<<<EOF
<IfModule mod_authz_core.c>
    Require all granted
</IfModule>
<IfModule !mod_authz_core.c>
    Order Deny,Allow
    Allow from all
</IfModule>
EOF
		);

		file_put_contents(
			$dirName . '/html/style.css',
			<<<EOF
table.nightlyinfo {
	width: 100%;
}

table.nightlyinfo td, table.nightlyinfo th {
	padding: 5px;
}

table.nightlyinfo tr:nth-child(even) {
  background-color: #b3b6b3;
}


table.nightlyinfo tr:nth-child(odd) {
  background-color: white;
}
EOF
		);

	}

	protected function createNightlyTxt()
	{
		$lines = array();

		$data = $this->preparePackageInformation();
		$max  = $data['maxlen'];

		foreach ($data as $name => $package)
		{
			if ($name == 'maxlen')
			{
				continue;
			}

			$line = sprintf('%s %s %s',
				str_pad($name, strlen($max['name'])),
				str_pad($package['version'], strlen($max['version'])),
				str_pad($package['lastchange'], strlen($max['lastchange']))
			);

			if ($package['homepage'])
			{
				$lines[$package['homepage']][] = $line;
			}
			else
			{
				$lines['other'][] = $line;
			}
		}

		$lineLen = strlen($max['name']) + strlen($max['version']) +  strlen($max['lastchange']) + 2;

		$text = trim(sprintf('%s %s %s',
			str_pad('Name', strlen($max['name'])),
			str_pad('Version', strlen($max['version'])),
			str_pad('Last modification', strlen($max['lastchange'])))
		). "\n";

		$text .= str_repeat('=', $lineLen) . "\n";

		foreach ($lines as $url => $packages)
		{
			if ($url == 'other')
			{
				continue;
			}

			$text .= $url . "\n";
			$text .= implode("\n", $packages);
			$text .= "\n";
			$text .= str_repeat('-', $lineLen);
			$text .= "\n\n";
		}

		$text .= 'other' . "\n";
		$text .= implode("\n", $lines['other']);
		$text .= "\n\n";

		file_put_contents(
			$this->package . '/nightly.txt',
			$text
		);
	}

	protected function createNightlyXml()
	{
		$filename = $this->input->getOption('xml');

		if (!$filename)
		{
			return;
		}

		$data = $this->preparePackageInformation();

		$text = '<versioninfo>
';

		foreach ($data as $name => $package)
		{
			if ($name == 'maxlen')
			{
				continue;
			}

			$text .= sprintf(
				'	<extension>
		<name>%s</name>
		<maintainer>%s</maintainer>
		<hash>%s</hash>
		<lastchange>%s</lastchange>
	</extension>
',
				$name,
				$package['homepage'] ? $package['homepage'] : 'other',
				$package['version'],
				$package['lastchange']
			);
		}

		$text .= '</versioninfo>
';

		file_put_contents(
			$filename,
			$text
		);
	}



	/**
	 * Deploy the contents of the build directory either into an archive or to the deploy path
	 * (depending on command line parameter).
	 *
	 */
	protected function deploy()
	{
		$output = $this->input->getArgument('output');
		if ($output == 'package.zip')
		{
			if ($this->input->getOption('dir'))
			{
				$output = $this->encodedName;
			}
			else
			{
				$output = $this->encodedName . '.zip';
			}
		}

		if ($this->input->getOption('dir')) {
			$this->output->writeln('  - <info>Create package ' . $output . '</info>');
			$this->copy($this->package, $output, new Filesystem());
		}
		else {
			$this->output->writeln('  - <info>Create package archive' . $output . '</info>');
			$zip = new \ZipArchive();
			$zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
			$this->addToZipArchive($zip, $this->package, '');
			$zip->close();
		}
	}

	/**
	 * Clean the build space.
	 */
	protected function cleanup()
	{
		$this->output->writeln('  - <info>Cleanup</info>');
//		$this->fs->remove($this->repository);
		$this->fs->remove($this->package);
	}

	/**
	 * Executes the command.
	 *
	 * @param InputInterface  $input  An InputInterface instance
	 *
	 * @param OutputInterface $output An OutputInterface instance
	 *
	 * @return null|integer null or 0 if everything went fine, or an error code
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->input  = $input;
		$this->output = $output;
		$this->fs     = new Filesystem();

		$this->prepare();
		$this->getComposer();
		$this->doInstall();
		$this->createBackendModule();
		$this->assemblePackages();
		$this->createAutoloader($this->nightlyModule, $this->classmap);
		$this->saveConfig();
		$this->createNightlyTxt();
		$this->createNightlyXml();
		$this->deploy();
//		$this->cleanup();
	}

	/**
	 * Recursive copy of a file/folder to another location.
	 *
	 * This method uses absolute pathes.
	 *
	 * @param string $source The source to copy.
	 *
	 * @param string $target The destination where to place the files.
	 */
	protected function copy($source, $target)
	{
		if (is_dir($source)) {
			$this->fs->mkdir($target);
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->copy($item, $target . '/' . basename($item));
			}
		}
		else {
			$this->fs->copy($source, $target);
		}
	}

	/**
	 * Determine the type of a composer package by calling composer and examining the result.
	 *
	 * @param string $package The name of the package.
	 *
	 * @param string $version The version of the package.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException
	 */
	protected function getPackageType($package, $version)
	{
		$process = new Process('php ' . escapeshellarg($this->repository . '/composer.phar') . ' show ' . escapeshellarg($package) . ' ' . escapeshellarg($version));
		$process->setTimeout(120);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$details = $process->getOutput();
		foreach (explode("\n", $details) as $line) {
			$parts = explode(':', $line);
			$parts = array_map('trim', $parts);
			if ($parts[0] == 'type') {
				return $parts[1];
			}
		}
		return 'library';
	}

	/**
	 * Recursively files/folders to the given zip file.
	 *
	 * @param \ZipArchive $zip    The archive to which the files shall get added to.
	 *
	 * @param string      $source The source path to add.
	 *
	 * @param string      $target The destination path where the files shall get placed to.
	 *
	 */
	protected function addToZipArchive(\ZipArchive $zip, $source, $target)
	{
		if (is_dir($source)) {
			if ($target) {
				$zip->addEmptyDir($target);
			}
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->addToZipArchive($zip, $item, ($target ? $target . '/' : '') . basename($item));
			}
		}
		else {
			$zip->addFile($source, $target);
		}
	}
}
