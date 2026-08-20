<div align="center">
    <h1>radix - The GEWIS Website and Decision & Membership Database</h1>

<!-- Shield group -->
[![Latest Release](https://img.shields.io/github/v/release/GEWIS/radix)](https://github.com/GEWIS/radix/releases)
[![Build](https://img.shields.io/github/check-runs/GEWIS/radix/main)](https://github.com/GEWIS/radix/actions)
[![Uptime](https://uptime.gewis.nl/api/badge/1/uptime)](https://gewis.nl/en/)
[![Issues](https://img.shields.io/github/issues/GEWIS/radix)](https://github.com/GEWIS/radix/issues)
[![Commit Activity](https://img.shields.io/github/commit-activity/m/GEWIS/radix/main)](https://github.com/GEWIS/radix/commits/main)
[![License](https://img.shields.io/github/license/GEWIS/radix.svg)](./LICENSE.txt)

<p>radix is the <a href="https://gewis.nl" target="_blank">website</a> and the decision and membership database of GEWIS - <em>GEmeenschap van Wiskunde en Informatica Studenten</em> - in a single application. It succeeds GEWISWEB and GEWISDB, which were separate applications until they were merged here.</p>
</div>

## What radix Is
Two things that were always about the same association, and were always kept in step by hand, now live in one codebase:

- **The register.** Every meeting, decision, member, body and mailing list of the association. This is the ledger: the
  authoritative record of what GEWIS decided and who its members are, written by the board and read by every other
  GEWIS system through the API.
- **The website.** Activities, photos, course documents, custom pages, and the careers portal, for members and for the
  world.

They meet in the middle. The website shows the association's decisions, the bodies that exist because of them, and the
members installed in those bodies; it now reads them from the same application that records them, instead of copying
them across an HTTP boundary twice an hour.

That leaves radix with two databases, and the split matters for anything that touches data:

- **PostgreSQL** holds the ledger - the editable source of truth, behind the `default` connection.
- **MariaDB** holds everything else, behind the `web` connection: the website's own data, and the projection of the
  ledger that the site and the API read from.

## Features
The website provides its members and other visitors with lots of functionality:

- **Activities**:
    - Create activities with a wide range of options for sign-up lists.
    - Enables members to sign up for various events and activities, enhancing engagement and participation.

- **Career**:
    - Allows companies that collaborate with GEWIS to publish job vacancies and opportunities.
    - Facilitates connections between students and potential employers, aiding in career development.

- **Decisions**:
    - Provides a platform for members to view and interact with decisions and meetings.
    - Ensures transparency and member involvement in the decision-making process.

- **Education**:
    - Offers an extensive archive of course documents, including exams and summaries.
    - Serves as a valuable resource for students looking to study or review past materials.

- **Pages**:
    - Custom pages created by the board to provide dynamic content.
    - Allows for flexible and timely updates to information and announcements.

- **Photos**:
    - Maintains a comprehensive photo archive of the numerous activities organised by GEWIS.
    - Helps preserve and share memories of events and gatherings with the community.

The decision and membership database provides the board and other GEWIS systems with lots of functionality:

- **Management of Decisions**:
    - Organise and manage various types of meetings.
    - Handle a range of decisions, from financial budgets and statements to the installation of members in various organs, along with customisable decisions.
    - While decisions can be altered to reflect changes, they remain more or less immutable to maintain historical accuracy.

- **Management of Memberships**:
    - The join page, which [join.gewis.nl](https://join.gewis.nl) redirects onto, facilitates new memberships and can automatically collect membership fees through Stripe.
    - Validation of student information ensures that all member information is accurate.
    - Allows for detailed and precise editing of member information.

- **Checker Module**:
    - Ensures that the database remains in a consistent state by enforcing many constraints derived from the Articles of Association and Internal Regulations.
    - For instance, it prevents members from being installed in an organ if their membership has expired, ensuring adherence to (regulatory) requirements.

- **API**:
    - Serves a consistent projection of the ledger, so decisions and membership information can be queried without touching the register itself.
    - Used by most GEWIS systems as a single, reliable source of truth, ensuring consistency and accuracy across all systems.

And there is plenty more!

## Getting Started
radix is built on PHP and the [Symfony framework](https://symfony.com/). The Symfony framework provides a solid foundation for building scalable and maintainable web applications.

### Prerequisites
We recommend developing natively on a Linux machine or through WSL2 on Windows (note: Arch-based distributions are **not** recommended) with the [PhpStorm](https://www.jetbrains.com/phpstorm/) IDE or another IDE with good support for PHP.<br/>
Alternatively, you can use [GitHub Codespaces](https://github.com/codespaces/new?hide_repo_select=false&repo=gewis/radix&geo=EuropeWest&machine=basicLinux32gb).

You will need at least:
- `docker` and `docker compose` (make sure that you have enabled [Buildkit](https://docs.docker.com/build/buildkit/#getting-started))
- `git`
- `make`
- A `.xlf` file editor (e.g. POEdit)

PHP, Composer, and all other runtime tooling live inside the Docker image, no need to install them yourself.

It is possible to use [rootless docker](https://docs.docker.com/engine/security/rootless/) on many Linux systems. For this, install `uidmap`, ensure IP forwarding is enabled, run `dockerd-rootless-setuptool.sh install` and set the `DOCKER_HOST` variable in your profile (e.g. `.bashrc`).

### Installation
To set up radix locally, follow these steps:

1. [Fork the repository](https://github.com/GEWIS/radix/fork).
2. Clone your fork (`git clone git@github.com:{username}/radix.git`).
3. Run `make start` to build and serve the application (a `.env.local` will be created for you; alter it to your needs). The first build may take 5-10 minutes.
4. Run `make seed` to get some test data (migrations will run automatically).
5. Go to [`http://localhost/`](http://localhost/) in your browser and you are greeted with the GEWIS website.
6. Log in with membership number `8000` and the password `gewiswebgewis`.
7. The register is part of the administration, under [`http://localhost/en/admin/`](http://localhost/en/admin/); the same sign-in reaches it, and member `8000` holds both the website's and the register's administrator roles.

#### Other Accessible Services
During development, several other services are accessible on your local machine:

- **phpMyAdmin** - Management interface for MariaDB at [`http://localhost:8080/`](http://localhost:8080/).
- **pgAdmin** - Management interface for PostgreSQL at [`http://localhost:8081/`](http://localhost:8081/).
- **MailPit** - Email testing at [`http://localhost:8025/`](http://localhost:8025/).
- **Mailman** - Mailing list management at [`http://localhost:8021/`](http://localhost:8021/) (its REST API is on `8020`).
- **Listmonk** - Newsletter management at [`http://localhost:8022/`](http://localhost:8022/).
- **RabbitMQ** - Message broker management at [`http://localhost:15672/`](http://localhost:15672/).
- **Matomo** - Analytics platform at [`http://localhost:82/`](http://localhost:82/).

### Deployment
There are two routes to production, running the same image (`abc.docker-registry.gewis.nl/web/radix/app`) and reading
the same environment variables. What differs is where those variables come from and how migrations are ordered.

- **Docker, through Portainer.** `compose.yaml` *is* the production stack; `compose.override.yaml` is what turns it
  into the development one, and `docker compose up` in a checkout loads both. Portainer names `compose.yaml` on its
  own, which is also what stops compose merging the development override. Every variable is guarded with
  `${VAR:?...}`, so one that Portainer's stack environment is missing aborts the deploy rather than falling back to a
  development default. The single `app` container runs the migrations on start.
- **Kubernetes, with sealed secrets.** `k8s/`, applied with `kubectl apply -k`. Configuration is split by
  sensitivity — a ConfigMap for what may be read, a SealedSecret for what may not — and migrations move to a Job that
  runs before the rollout, because the replicas would otherwise race each other for them. See its
  [README](k8s/README.md) for how to seal the secrets.

Values that are the same on every deployment are in neither: they are `env(NAME): default` parameters in
`config/services.yaml`, and changing one is a code change.

### Contributing
We welcome contributions from the community, especially GEWIS members! To contribute:

1. Perform the steps from [Installation](#installation).
2. Create your feature of bug fix branch (`git switch -c feature/my-amazing-feature`).
3. Commit your changes (`git commit -m 'feat: added my amazing feature'`). <ins>**NOTE:** radix requires commits to be signed, see [this GitHub article](https://docs.github.com/en/authentication/managing-commit-signature-verification/signing-commits) for more information on how to sign commits.</ins>
4. Push to the branch (`git push origin feature/my-amazing-feature`).
5. Open a pull request.

> [!NOTE]
> More detailed information on GEWIS' contribution guidelines, including conventions on branch names and commit messages, can be found in the [contribution guidelines](https://github.com/GEWIS/.github/blob/main/CONTRIBUTING.md).

### Useful Commands During Development
While developing, use these commonly used commands from the Makefile:

- `make bash` - Shell into the FrankenPHP `app` container.
- `make sf c='...'` - Run a Symfony console command inside the container (e.g. `make sf c=check:database`).
- `make composer c='...'` - Run a Composer command inside the container (e.g. `make composer c=update`).
- `make translations` - Extract translatable strings into the `.xlf` files. Run this whenever you add or edit a user-facing string in PHP, Twig, or a form type.
- `make lint` / `make lint-fix` - Run PHP_CodeSniffer (or PHPCBF to autofix) against the project's coding standard.
- `make lint-twig` - Validate the Twig templates.
- `make phpstan` - Perform static analysis using PHPStan.
- `make igor` - Run Igor to validate the codebase for FrankenPHP's worker mode.
- `make cc` - Clear the cache and restart the worker.

For a complete list of available commands, run `make help`.

> [!TIP]
> If you are using AI coding tools (Claude Code, Copilot, Cursor, ...), they will pick up `AGENTS.md` automatically. It documents architecture, conventions, and gotchas in more depth than this README. However, it is not only for AI coding tools, have a look too if you are interested.

### Testing
The test suite runs with PHPUnit, inside the `app` container, against isolated copies of both databases:

- `make test-prepare` - Build the test schemas and load the seed into them. Run this once, and again after a schema or fixture change; the tests roll back their own writes, so the seed survives a run.
- `make test` - Run the suite. Pass `c=` to hand options to PHPUnit, for example `make test c="--stop-on-failure"`.

Tests that need a database run against real PostgreSQL and MariaDB matching production rather than an in-memory
substitute, because the schema uses more of both than a substitute can reproduce.

Beyond the tests, `make sf c=check:database` runs the consistency checks derived from the Articles of Association and
Internal Regulations over the register. It is worth running after anything that changes decisions or installations.

### Project Structure
A general overview of important folders required for the functioning of the application:

```txt
./
├── assets                  # Front-end sources (Sass, TypeScript Stimulus controllers).
├── config                  # Global configuration files for the application.
├── data                    # Persistent private data-related files, such as cryptographic keys and logs.
├── docker                  # Docker-related files to construct the containers.
├── migrations              # Doctrine migrations, one directory per database.
├── public                  # Publicly accessible files, including the entry point (index.php).
├── src                     # The application itself, grouped by the role each class plays.
├── templates               # Twig templates.
├── tests                   # The test suite.
└── translations            # The `.xlf` translation files.
```

Within `src`, classes are grouped by what they do rather than by feature: `Controller`, `Service`, `Entity`,
`Repository`, `Form`, `Twig`, and so on. Each of those is then split by domain - `Database` for the ledger of what the
association decided and who its members are, `Decision` for the projection of it that the site and the API read,
`Report` for the machinery that keeps that projection level with the ledger, `Checker` for the consistency checks,
`Activity`, `Career`, `Education`, `Frontpage` and `Photo` for the website's own features, `User` for accounts and the
API, and `Application` for what is shared.

### Testing Stripe Behaviour
Some additional configuration needs to be done to set up the Stripe API:

* Create a restricted key on https://dashboard.stripe.com/test/apikeys and set it in the `STRIPE_SECRET_KEY` environment variable (check `.env.local.dist` for the permissions to set)
* Copy the publishable key from https://dashboard.stripe.com/test/apikeys and set it in the `STRIPE_PUBLISHABLE_KEY` environment variable
* Copy the webhook signing secret from the output of `make stripewebhooksecret`
* Create a product with a one-off price on https://dashboard.stripe.com/test/products?active=true and copy its price ID to `STRIPE_MEMBERSHIP_PRICE_ID`

Tip: to reduce waiting time for checkout sessions to expire, you can speed up this process by invoking `docker compose exec stripe stripe checkout sessions expire cs_test_fromcheckoutsessionstable`.
This will enable cash payment and send the retry email.

Note: the links in the e-mails do not resolve in the development setup. Replace the host with `http://localhost/` to follow them.

### Using the API
To experiment with the API, import the `openapi.yaml` file into your favourite REST client.

Alternatively, you can use PowerShell, for example:

```powershell
((Invoke-WebRequest -Uri http://localhost/api/organFunctions -Headers @{"Authorization" = "Bearer APITOKEN"; "Accept" = "application/vnd.gewis.gewisdb+json;version=4.3.3"}).Content | ConvertFrom-Json).data | Format-List
```

## License
This software is licensed under the GNU General Public License v3.0 (GPL-3.0), see [LICENSE](./LICENSE.txt).
