# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Laravel, Livewire, Alpine.js, Tailwind CSS, Blade, SQLite for local development, Pest, and April UI as the primary UI reference. This stack was specified in the project brief.

## Users

The server administrator managing Laravel applications on the same Ubuntu VPS.

## Product Purpose

Laravel Manager prepares and manages Laravel applications on one self-hosted Ubuntu VPS so routine server administration is removed from normal Laravel development.

## Positioning

One Laravel Manager installation on one Ubuntu VPS manages many Laravel applications hosted on that server.

## Operating Context

The intended workflow is: create an app, connect it to GitHub, clone the repository locally, develop normally, push to GitHub, and deploy automatically. RUN 01 creates only local project records; server provisioning and deployment are later RUNs.

## Capabilities and Constraints

- The target production environment is Ubuntu 24.04 LTS with Apache, PHP-FPM, MySQL, Git, Composer, Node.js/npm, Certbot, GitHub, and Cloudflare wildcard DNS.
- RUN 01 includes authentication, Apps, Create App, project detail, settings, project/deployment records and statuses, and base-domain generation.
- RUN 01 does not execute shell commands or provision server resources.
- Public registration is disabled; the initial administrator is created through a documented development mechanism.
- Do not expand to other operating systems, web servers, cloud providers, or deployment platforms unless requested in a future RUN.

## Brand Commitments

- Product name: Laravel Manager.
- April UI is the primary UI component and template reference.
- Interface direction: clean, modern, restrained, professional, developer-oriented, easy to scan, and responsive. These are constraints from the project brief.

## Evidence on Hand

No production data, customer claims, or brand assets have been supplied. Do not invent them.

## Product Principles

- Prefer simplicity over theoretical flexibility.
- Keep product scope specific to Laravel apps on the same VPS.
- Remove repetitive server administration from normal Laravel development.
- Favor Laravel conventions, clear code, and few dependencies.
