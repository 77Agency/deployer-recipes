<?php

namespace Deployer;

require 'recipe/common.php';

/*————————————————————
  CONFIG & DEFAULTS
————————————————————*/

set('branch', 'master');
set('shared_files', []);
set('shared_dirs', []);
set('writable_dirs', []);
set('keep_releases', 3);
set('default_stage', 'local'); 

set('development', false);
set('bin/composer', 'composer');
set('bin/php', 'php');
set('bin/git', 'git');
set('bin/npm', 'npm');
set('composer_options', 'install --verbose --prefer-dist --optimize-autoloader --no-progress --no-interaction');

serverList('config/servers.yml');

localServer('local')
    ->set('development', true)
    ->set('deploy_path', __DIR__ . '/deploy')
    ->set('test_url', 'http://localhost:3000')
    ->stage('local');

/*————————————————————
  DEPLOYMENT
————————————————————*/

task('client:npm:install', function () {
    runLocally('npm install', 600);
})->desc('Install NPM packages');

task('client:npm:build', function () {
    $stage = input()->getArgument('stage');
    runLocally("rm -rf dist && NODE_ENV=$stage npm run build", 600);
})->desc('Build Webpack project');

task('client:npm:clean', function () {
    runLocally('rm -rf node_modules');
})->desc('Remove NPM packages');

task('client:build', [
    'client:npm:clean',
    'client:npm:install',
    'client:npm:build',
])->desc('Build client');

task('deploy:client', function () {
    runLocally('tar cvzf client-dist.tgz -C dist .');
    upload('client-dist.tgz', '{{download_path}}/release/client-dist.tgz');
    run('cd {{release_path}} && tar zxvf client-dist.tgz && rm client-dist.tgz');
})->desc('Upload client');

task('deploy:rollbar', function () {
    $stages = implode(',', get('stages'));
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
    'deploy:client',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
])->desc('Deploy your project');
after('deploy', 'deploy:rollbar');

/*————————————————————
  TESTING
————————————————————*/

task('test:karma', function () {
    runLocally('npm run test || exit 0', 300);
})->desc('Run Karma tests');

task('test', [
    'test:karma',
])->desc('Test your project');

/*————————————————————
  STATIC CODE ANALISYS
————————————————————*/

task('analyze:jscpd', function () {
    runLocally('node_modules/jscpd/bin/jscpd -p src --min-tokens 50 -r xml -o jscpd.xml -e **/*.min.js');
})->desc('Run JS Copy/Paste Detector (CPD) analysis');

task('analyze', [
    'analyze:jscpd',
])->desc('Analyze your project');
