<?php

namespace Deployer;

require 'recipe/laravel.php';

/*————————————————————
  CONFIG & DEFAULTS
————————————————————*/

set('branch', 'master');
set('shared_files', [
    '.env',
]);
set('shared_dirs', [
    'storage/app',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
]);
set('writable_dirs', ['bootstrap/cache', 'storage']);
set('keep_releases', 3);
set('default_stage', 'local'); 

env('development', false);
env('bin/composer', 'composer');
env('bin/php', 'php');
env('bin/git', 'git');
env('bin/npm', 'npm');
env('composer_options', 'install --verbose --prefer-dist --optimize-autoloader --no-progress --no-interaction');
env('protractor_options', '--seleniumAddress http://95.130.39.72:4444/wd/hub');

serverList('config/servers.yml');

localServer('local')
    ->env('development', true)
    ->env('deploy_path', __DIR__ . '/deploy')
    ->env('test_url', 'http://localhost:3000')
    ->stage('local');

/*————————————————————
  DEPLOYMENT
————————————————————*/

task('client:npm:install', function () {
    runLocally('cd client && npm install', 600);
})->desc('Install NPM packages');

task('client:npm:build', function () {
    runLocally('cd client && rm -rf dist && npm run build', 600);
})->desc('Build Webpack project');

task('client:npm:clean', function () {
    runLocally('cd client && rm -rf node_modules');
})->desc('Remove NPM packages');

task('client:build', [
    'client:npm:clean',
    'client:npm:install',
    'client:npm:build',
])->desc('Build client');

task('deploy:client', function () {
    runLocally('tar cvzf client-dist.tgz -C client/dist .');
    upload('client-dist.tgz', '{{release_path}}/client-dist.tgz');
    run('cd {{release_path}} && tar zxvf client-dist.tgz -C public');
})->desc('Upload client');

task('deploy:vendors', function () {
    $composer = env('bin/composer');
    $envVars = env('env_vars') ? 'export ' . env('env_vars') . ' &&' : '';
    $dev = env('development');
    $composerOptions = env('composer_options') . ' ' . ($dev ? '--dev' : '--no-dev');
    run("cd {{release_path}} && $envVars $composer {$composerOptions}");
})->desc('Installing vendors');

task('deploy:migrate', function () {
    $output = run('php {{deploy_path}}/release/artisan migrate --force');
    writeln('<info>'.$output.'</info>');
})->desc('Run Artisan database migrate');

task('deploy:migrate:refresh', function () {
    $output = run('php {{deploy_path}}/current/artisan migrate:refresh --seed --force');
    writeln('<info>'.$output.'</info>');
})->desc('Run Artisan database migrate');

task('deploy:seed', function () {
    $output = run('php {{deploy_path}}/current/artisan db:seed --force');
    writeln('<info>'.$output.'</info>');
})->desc('Run Artisan database seeding');
 
task('deploy:apidocs', function () {
    run('cd {{deploy_path}}/release && php artisan api:generate --routePrefix="api/*"');
})->desc('Run API docs generation');

task('deploy:key:generate', function () {
    $output = run('php {{deploy_path}}/release/artisan key:generate');
    writeln('<info>'.$output.'</info>');
})->desc('Run Laravel key generation');

task('deploy:rollbar', function () {
    $stages = implode(',', env('stages'));
    cd('{{deploy_path}}/current');
    run('curl https://api.rollbar.com/api/1/deploy/ ' .
        '-F access_token={{rollbar_access_token}} ' .
        "-F environment={$stages} " .
        '-F local_username=jenkins ' .
        '-F revision=`git log --pretty=format:"%H" -n 1`');
})->desc('Notify Rollbar about deployment');

task('deploy', [
    'client:build',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:vendors',
    'deploy:writable',
    'deploy:client',
    'deploy:apidocs',
    'artisan:migrate',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'artisan:cache:clear',
    'artisan:config:cache',
])->desc('Deploy your project');
after('deploy', 'deploy:rollbar');

/*————————————————————
  TESTING
————————————————————*/

task('test:behat', function () {
    runLocally('vendor/bin/behat -f junit -o behat_test_results || exit 0');
})->desc('Run Behat tests');

task('test:phpunit', function () {
    runLocally('vendor/bin/phpunit --log-junit phpunit.log.xml || exit 0');
})->desc('Run PHPUnit tests');

task('test:karma', function () {
    runLocally('cd client && npm run test || exit 0', 300);
})->desc('Run Karma tests');

task('test:protractor', function () {
    runLocally('cd client && npm run webdriver:update && node_modules/protractor/bin/protractor conf/protractor-dist.js --baseUrl {{url}} {{protractor_options}} || exit 0', 3000);
})->desc('Run Protractor tests');

task('test', [
    'test:behat',
    'test:phpunit',
    'test:karma',
    'test:protractor',
])->desc('Test your project');

/*————————————————————
  STATIC CODE ANALISYS
————————————————————*/

task('analyze:phpcs', function () {
    runLocally('vendor/bin/phpcs --standard=rulesets/phpcs.PSR2.custom.xml --tab-width=4 --report=checkstyle --report-file=phpcs.checkstyle.xml --extensions=php app tests');
})->desc('Run PHP CodeSniffer analysis');

task('analyze:phpmd', function () {
    runLocally('vendor/bin/phpmd app,config,database,features,resources,tests --suffixes php xml codesize,controversial,design,unusedcode,phpmd.naming.custom,phpmd.cleancode.custom --reportfile messdetector.xml');
})->desc('Run PHP Mess Dedector analysis');

task('analyze:phpcpd', function () {
    runLocally('vendor/bin/phpcpd --names=*.php app bootstrap config database features resources tests --log-pmd phpcpd.xml');
})->desc('Run PHP Copy/Paste Detector (CPD) analysis');

task('analyze:pdepend', function () {
    runLocally('vendor/bin/pdepend --jdepend-xml=pdepend.output.xml --jdepend-chart=pdepend.chart.svg --overview-pyramid=pdepend.pyramid.svg --suffix=php app');
})->desc('Run PHP PDepend analysis');

task('analyze', [
    'analyze:phpcs',
    'analyze:phpmd',
    'analyze:phpcpd',
    'analyze:pdepend',
])->desc('Analyze your project');
