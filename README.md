## Introduction

Taxi is a multi-site manager. It allows for multiple sites to be easily managed and setup using only a few commands.
Taxi is ideal for developers who manage multiple laravel/php websites which currently use laravel valet, but require 
some management to keep them all up to date.  

## Official Documentation

Taxi requires laravel valet in order to work, please see the installation documents [here](https://laravel.com/docs/10.x/valet).

Taxi must be installed globally.

``composer global require richardstyles/taxi``

After composer has installed, then run;

`taxi install`

`taxi trust` (optional)

Whilst not required it is advised to run trust, so taxi is able to run valet commands such as `valet secure`.

To generate a taxi configuration file run

`taxi call`

This defaults to a multi site setup (see below for configuration details). 
You MAY have many different folders containing different taxi.json files, when you run build, reset or valid these 
will only affect the current taxt.json file you are looking at.

`taxi call --single`

Will generate a single site example for ease.

Once you have your configuration file set, simply run

`taxi build`

This will clone your repositories, link them automatically to valet and run any specific commands you have set.

To reset the state of a taxi managed site, simple run

`taxi reset`

This will reset your sites, back to your specified default branches, stash any changes, and run any reset commands you have set. 

### Configuration

```json
{
  "valet": true,                // enable valet commands
  "repos": [
    {
      "name": "laravel-1",      // Name of the application (also the valet site name)
      "php": "8.1",             // version of PHP to isolate to (optional)
      "branch": "main",         // default branch for this repository
      "secure": true,           // run valet secure during build (optional)
      "url": "https://github.com/laravel/laravel",  // repository URL 
      "post-build": [           // commands which should be run post build 
        "cp .env.example .env",
        "php artisan key:generate"
      ],
      "post-reset": []          // commands which should be run post reset 
    },
    {                           // multiple repositorys can be specified
      "name": "laravel-2",
      "php": "8.1",
      "branch": "main",
      "url": "https://github.com/laravel/laravel",
      "post-build": [
        "cp .env.example .env"
      ],
      "post-reset": []
    }
  ],
  "hooks": {                    // hooks which are run on ALL repositories
    "build": [                  // all commands run during build
      "npm install",
      "npm run production",
      "composer install"
    ],
    "reset": [                  // all commands run during reset
      "rm -rf vendor && rm composer.lock",
      "composer install",
      "npm run production"
    ]
  }
}
```

### Commands
#### Taxi Build
Build the sites listed in the `taxi.json` and run any associated install commands.
#### Taxi call
Generate an example `taxi.json` file which can be changed to suite your needs
#### Taxi install
Install Taxi into user bin folder so can be run from anywhere
#### Taxi list
List all commands available
#### Taxi reset
Reset a Taxi managed site to a default setting based off the configuration commands
#### Taxi sites
List all sites which only use Taxi
#### Taxi trust
Enables Taxi to run as a sudo user, avoids repeated requests for passwords.
#### Taxi type
Identify the type of Taxi, single or multi site managed
#### Taxi valet
List all Laravel valet sites, and list those which have Taxi enabled.
#### Taxi valid
Check to see if the taxi configuration is valid

## Contributing

Have an idea which can help improve Taxi, then please PR detailing your suggestion and improvements.

## Code of Conduct

Be kind.

## Security Vulnerabilities

Please review [our security policy](https://github.com/richardstyles/taxi/security/policy) on how to report security vulnerabilities.

## License

Taxi is open-sourced software licensed under the [GNU General Public License version 3](https://opensource.org/license/gpl-3-0/).