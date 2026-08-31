# Changelog

## 0.1.0 (2026-08-31)


### Features

* add state store drivers, client wiring and core CLI ([512c9f9](https://github.com/digitalygo/envai-api-php-sdk/commit/512c9f916e859a89f938811330792593a820d89c))
* **atp:** add SSE parser, typed events and turn runtime ([8e8444b](https://github.com/digitalygo/envai-api-php-sdk/commit/8e8444ba3adb573684b46a369362b46adb26b61b))
* bootstrap package with shared contracts and config ([0dc96bb](https://github.com/digitalygo/envai-api-php-sdk/commit/0dc96bb38144fb54e9239105223c54bb23f095f1))
* **client:** add 11 services covering all 52 contract operations ([1446316](https://github.com/digitalygo/envai-api-php-sdk/commit/1446316a1eda3e796a373b1f569f6712ca2b0f03))
* **definitions:** add markdown agents, tool registry, teams and manifest hashing ([132c758](https://github.com/digitalygo/envai-api-php-sdk/commit/132c758cc4f741b60f5660edaa161e622f414c10))
* **http:** add curl transport with SSE stream connections ([8abf3c5](https://github.com/digitalygo/envai-api-php-sdk/commit/8abf3c5f3bdb46dba0a687403bfa837bde3a08ac))
* **http:** add request executor, error mapping and codec ([71ab834](https://github.com/digitalygo/envai-api-php-sdk/commit/71ab83443f591268a5de271fd638b2ed846c017b))
* **laravel:** add optional service provider, state store and lazy sync ([3396845](https://github.com/digitalygo/envai-api-php-sdk/commit/3396845a660b662fd3c8565d391fa36e1f31c2ac))
* **models:** add typed DTOs for all 52 contract operations ([eda3175](https://github.com/digitalygo/envai-api-php-sdk/commit/eda3175983f6b555e96afb4f7fb24ea2a105ca8f))
* **sync:** add three-way planner, reconciler and readiness gate ([ed529b8](https://github.com/digitalygo/envai-api-php-sdk/commit/ed529b8e1e6495d01ea4482901caf88676f51f37))
* **tools:** add verified client tool runtime with call store ([6cb8ca7](https://github.com/digitalygo/envai-api-php-sdk/commit/6cb8ca7510a870b0b1cfa8a7f169ee67acd9950f))
* **webhooks:** add contract-neutral core pipeline ([3139997](https://github.com/digitalygo/envai-api-php-sdk/commit/31399979062b865189c73260579862488474007b))
* **webhooks:** add dormant laravel endpoint registration ([0dd04a9](https://github.com/digitalygo/envai-api-php-sdk/commit/0dd04a9612ce5f3f2c438f6a6a9eb6b779acdc40))
* **webhooks:** add laravel action, event and dispatch adapters ([8924003](https://github.com/digitalygo/envai-api-php-sdk/commit/892400367d6f53b08632a24c0965196bf6501412))
* **webhooks:** add laravel idempotency store ([d28f88b](https://github.com/digitalygo/envai-api-php-sdk/commit/d28f88b74cc334ec063653e79dea341e049e7eaa))
* **webhooks:** add webhook receipts migration ([8f9d675](https://github.com/digitalygo/envai-api-php-sdk/commit/8f9d67550a4bbfcefb8551d4f43fd81fe6516fc2))
* **webhooks:** retain verified receipt metadata ([0891d60](https://github.com/digitalygo/envai-api-php-sdk/commit/0891d6040ead3e2f5345ab93b1509b8a7a0e4921))


### Bug Fixes

* **compat:** restore php 8.2-8.4 hydration and stub compatibility ([89d78cb](https://github.com/digitalygo/envai-api-php-sdk/commit/89d78cbbb6e7e917103cd812f78b98c4e83a92c9))
* **images:** accept decimal model setting ranges ([16986f6](https://github.com/digitalygo/envai-api-php-sdk/commit/16986f6b0269c6c7b11925837fb08cc014615002))
* **security:** redact URIs, sanitize handler errors, harden sync locks ([980a7b0](https://github.com/digitalygo/envai-api-php-sdk/commit/980a7b01807cbe5e65f662a6791fd1f5ea542f5d))
* **sync:** match platform prompt trimming and member enrichment ([c8497df](https://github.com/digitalygo/envai-api-php-sdk/commit/c8497dfd1bc33ebaf54a2ee4598f727b0acf6aca))
* **tools:** narrow native argument conversion ([e4831fe](https://github.com/digitalygo/envai-api-php-sdk/commit/e4831fe547661c020a0e0f57212413526d1984bc))
* **tools:** preserve JSON object semantics in tool request args ([90e4139](https://github.com/digitalygo/envai-api-php-sdk/commit/90e4139a5d3ddc5bd37b909c8613c68e90494672))
* **tools:** unwrap verified nested arguments ([a5a44d3](https://github.com/digitalygo/envai-api-php-sdk/commit/a5a44d3860575a9b2f29554f1f25f6b4cc8d0e3a))


### Miscellaneous Chores

* ignore debug app and expose Laravel auto-discovery ([f89a8e8](https://github.com/digitalygo/envai-api-php-sdk/commit/f89a8e8adbdda5d0cf6b85e399e55fd353aa9f50))
* register sync test suite ([9a25307](https://github.com/digitalygo/envai-api-php-sdk/commit/9a25307a9eeecedcda377e2969b2122e3f47b702))
* register wave 1 test suites and ignore IDE dirs ([2dfca25](https://github.com/digitalygo/envai-api-php-sdk/commit/2dfca253e074c01b00c62502e0b31f69e09e5aea))
* register wave 2 test suites ([b3095ef](https://github.com/digitalygo/envai-api-php-sdk/commit/b3095ef0dd429d8063cc4f6ac4ef724920cb7d19))
