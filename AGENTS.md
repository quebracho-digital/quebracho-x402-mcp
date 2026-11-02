# AGENTS.md — quebracho-x402-mcp

1. Read `CONTEXT.md` if it exists (it is local, outside git) and whatever it links to, and `DECISIONS.md`, before touching code.
2. Environment: DDEV (`ddev start`; PHP runs inside it: `ddev composer …`, `ddev exec php …`, `ddev exec vendor/bin/phpunit`). There is no PHP on the host.
3. Style: idiomatic Symfony (attributes, constructor DI, strict types), PSR in the library, no dependency that a couple of lines can replace.
4. Every step of the plan ends with its "done when" criterion verified and recorded. Do not count as done what only compiles.
5. Never real keys or mainnet. Doubts about security or money: stop and ask the maintainer.
6. Before closing: update `CONTEXT.md` (Status section) and leave the maintainer a three-line summary in the docs inbox (path in `CONTEXT.md`): what changed, what was verified, what is next.
7. Do not commit or push unless the maintainer asks (the repo is public: check for secrets first).
