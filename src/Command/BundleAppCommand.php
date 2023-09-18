<?php 

namespace VISU\Bundler\Command;

use ClanCats\Container\Container;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VISU\Bundler\Command\Exception\BundlerException;
use VISU\Exception\ErrorException;

use VISU\Command\Command;

class BundleAppCommand extends Command
{
    /**
     * Constrcutor
     * 
     * @param Container $container We utilize the container to try to access some 
     *                             default values to bundle the application.
     */
    public function __construct(
        private Container $container
    )
    {
    }

    /**
     * The commands decsription displayed when listening commands
     * if null it will fallback to the description property
     */
    protected ?string $descriptionShort = 'Creates a self contained and portable application bundle';

    /**
     * An array of expected arguments 
     *
     * @var array<string, array<string, mixed>>
     */
    protected $expectedArguments = [
        'output' => [
            'prefix' => 'o',
            'description' => 'The directory where the bundle will be created',
            'type' => 'string',
        ],

        'os' => [
            'prefix' => 'os',
            'longPrefix' => 'operating-system',
            'description' => 'The operating system to bundle the application for',
            'type' => 'string',
        ],

        'project-name' => [
            'longPrefix' => 'project-name',
            'description' => 'The name of the application .( :project.bundler.name from the container )',
            'type' => 'string',
        ],

        'project-version' => [
            'longPrefix' => 'project-version',
            'description' => 'The version of the application .( :project.bundler.version from the container )',
            'type' => 'string',
        ],
    ];

    /**
     * Returns the output directory
     */
    public function getOutputDirectory(): string
    {
        if (!$outputDir = $this->cli->arguments->get('output')) {
            $outputDir = VISU_PATH_ROOT . '/dist';
        }

        // get the parent directory
        $parentDir = dirname($outputDir);
        if (!is_dir($parentDir)) {
            throw new BundlerException('The output root directory does not exist: ' . $parentDir);
        }

        // ensure to create the output directory
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0777, true)) {
                throw new BundlerException('Failed to create the output directory: ' . $outputDir);
            }
        }

        return $outputDir;
    }

    /**
     *. Execute this command 
     */
    public function execute()
    {
        $this->info('Bundling application...');

        if (!$os = $this->cli->arguments->get('os')) {
            // try to detect the operating system
            $detectedOs = strtolower(PHP_OS);

            $os = match ($detectedOs) {
                'darwin' => 'macos',
                'win32' => 'windows',
                default => null,
            };
        }

        switch ($os) {
            case 'windows':
                $this->createWindowsBundle();
                break;
            case 'macos':
                $this->createMacOSBundle();
                break;
            default:
                throw new BundlerException('Unsupported operating system: ' . $os);
        }
    }

    /**
     * Returns a project parameter that falls back to the container
     */
    private function getProjectParameter(string $name): string
    {
        if ($this->cli->arguments->exists('project-' . $name) || !$value = $this->cli->arguments->get('project-' . $name)) {
            $value = $this->container->getParameter('project.bundler.' . $name, $this->container->getParameter('project.' . $name));
        }

        if (!$value) throw new BundlerException('Failed to determine the ' . $name . ' of the application.');

        return $value;
    }
    /**
     * Returns the name of the application
     */
    private function getApplicationName(): string
    {
        return $this->getProjectParameter('name');
    }

    /**
     * Returns the version of the application
     */
    private function getApplicationVersion(): string
    {
        return $this->getProjectParameter('version');
    }

    /**
     * Creates a MacOS application bundle
     */
    private function createMacOSBundle() : void 
    {
        $outputDir = $this->getOutputDirectory();
        
        $projectName = $this->getApplicationName();
        $applicationPath = $outputDir . '/macos/' . $projectName . '.app';
        // entry script is basically the application name without any special characters
        $applicationEntryscript = preg_replace('/[^a-zA-Z0-9]/', '', $projectName);

        // if the application already exists we need to remove it first
        // but we have to ask the user first
        if (is_dir($applicationPath) || file_exists($applicationPath)) {
            $confirmation = $this->cli->confirm('The application already exists, do you want to overwrite it?');

            if (!$confirmation->confirmed()) {
                $this->info('Aborting...');
                return;
            }

            // remove the directory and all its contents recursively
            $it = new RecursiveDirectoryIterator($applicationPath, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $file) {
                if ($file->isDir()){
                    rmdir($file->getPathname());
                } elseif ($file->isFile() || $file->isLink()) {
                    unlink($file->getPathname());
                }
            }

            rmdir($applicationPath);
        }

        // create the application directory
        if (!mkdir($applicationPath, 0777, true)) {
            throw new BundlerException('Failed to create the application directory: ' . $applicationPath);
        }

        // paths
        $applicationContentsDir = $applicationPath . '/Contents';
        $applicationResourceDir = $applicationPath . '/Contents/Resources';
        $applicationMacOSDir = $applicationPath . '/Contents/MacOS';
        $applicationScriptPath = $applicationMacOSDir . '/' . $applicationEntryscript;

        // create project template
        mkdir($applicationContentsDir);
        mkdir($applicationMacOSDir);
        mkdir($applicationResourceDir);
        touch($applicationPath . '/Contents/Info.plist');
        touch($applicationPath . '/Contents/PkgInfo');
        touch($applicationScriptPath);


        // copy all application files except the "$outputDir" directory into the "Contents/Resources" directory
        $it = new RecursiveDirectoryIterator(VISU_PATH_ROOT, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
        foreach($files as $file) {

            $relativeFile = substr($file->getPathname(), strlen(VISU_PATH_ROOT) + 1);
            $relativeOutputDir = substr($outputDir, strlen(VISU_PATH_ROOT) + 1);

            // skip the output directory
            if (substr($relativeFile, 0, strlen($relativeOutputDir)) === $relativeOutputDir) {
                continue;
            }

            // skip hidden files and directories
            $parts = explode('/', $relativeFile);
            foreach ($parts as $part) {
                if (substr($part, 0, 1) === '.') {
                    continue 2;
                }
            }

            // skip any vendor directories already in the vendor directory
            // this can happen if you for example have a symlink to a vendor library
            // that has its own dependencies installed aswell. 
            if (substr_count($relativeFile, 'vendor') > 1) {
                continue;
            }

            if ($file->isDir()){
                $this->info('Creating directory: /' . $relativeFile);
                mkdir($applicationResourceDir . '/' . $relativeFile);
            } elseif ($file->isFile()) {
                $this->info('Copying file: /' . $relativeFile);
                copy($file->getPathname(), $applicationResourceDir . '/' . $relativeFile);
            }
        }

        // create a MacOS script that runs the application
        file_put_contents($applicationScriptPath, <<<EOF
        #!/bin/sh
        DIR="$( cd "$( dirname "\${BASH_SOURCE[0]}" )" && pwd )"
        "\$DIR/php" "\$DIR/../Resources/bin/play"
        exit 0
        EOF);

        // copy the php binary
        copy(VISU_PATH_ROOT . '/bin/php', $applicationMacOSDir . '/php');

        // make the script executable
        chmod($applicationScriptPath, 0777);
        chmod($applicationMacOSDir . '/php', 0777);

        // create the Info.plist file
        $infoPlist = <<<EOF
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
            <dict>
                <key>CFBundleExecutable</key>
                <string>{$applicationEntryscript}</string>
                <key>CFBundleIconFile</key>
                <string>icon.icns</string>
                <key>CFBundleIdentifier</key>
                <string>com.visu.{$projectName}</string>
                <key>CFBundleName</key>
                <string>{$projectName}</string>
                <key>CFBundlePackageType</key>
                <string>APPL</string>
                <key>CFBundleShortVersionString</key>
                <string>{$this->getApplicationVersion()}</string>
                <key>CFBundleVersion</key>
                <string>{$this->getApplicationVersion()}</string>
                <key>LSMinimumSystemVersion</key>
                <string>10.9</string>
                <key>NSHighResolutionCapable</key>
                <string>True</string>
            </dict>
        </plist>
        EOF;

        file_put_contents($applicationContentsDir . '/Info.plist', $infoPlist);

        $this->cli->green('Successfully created the application bundle: ' . $applicationPath);
    }
}
