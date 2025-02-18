# Elastically, **Elastica** based framework

![CI](https://github.com/jolicode/elastically/actions/workflows/ci.yml/badge.svg)

Opinionated [Elastica](https://github.com/ruflin/Elastica) based framework to bootstrap PHP and Elasticsearch / OpenSearch implementations.

Main features:

- <abbr title="Data Transfer Object">DTO</abbr> are **first class citizen**, you send PHP object as documents, and get objects back on search results, **like an ODM**;
- All indexes are versioned and aliased automatically;
- Mappings are done via YAML files, PHP or custom via `MappingProviderInterface`;
- Analysis is separated from mappings to ease reuse;
- 100% compatibility with [ruflin/elastica](https://github.com/ruflin/Elastica);
- Mapping migration capabilities with ReIndex;
- Symfony HttpClient compatible transport (**optional**);
- Symfony support (**optional**):
    - See dedicated [chapter](#usage-in-symfony);
    - Tested with Symfony 5.4 to 6;
    - Symfony Messenger Handler support (with or without spool);

**Require PHP 8.0+ and Elasticsearch 7+**.

Works with **Elasticsearch 8+** but is not officially supported by Elastica yet. Use with caution.

Works with **OpenSearch 1 and 2**.

You can check the [changelog](CHANGELOG.md) and the [upgrade](UPGRADE.md) documents.

## Installation

```
composer require jolicode/elastically
```

## Demo

> If you are using Symfony, you can move to the Symfony [chapter](#usage-in-symfony)

Quick example of what the library do on top of Elastica:

```php
// Your own DTO, or one generated by Jane (see below)
class Beer
{
    public string $foo;
    public string $bar;
}

use JoliCode\Elastically\Factory;
use JoliCode\Elastically\Model\Document;

// Factory object with Elastica options + new Elastically options in the same array
$factory = new Factory([
    // Where to find the mappings
    Factory::CONFIG_MAPPINGS_DIRECTORY => __DIR__.'/mappings',
    // What object to find in each index
    Factory::CONFIG_INDEX_CLASS_MAPPING => [
        'beers' => Beer::class,
    ],
]);

// Class to perform request, same as the Elastica Client
$client = $factory->buildClient();

// Class to build Indexes
$indexBuilder = $factory->buildIndexBuilder();

// Create the Index in Elasticsearch
$index = $indexBuilder->createIndex('beers');

// Set the proper aliases
$indexBuilder->markAsLive($index, 'beers');

// Class to index DTO(s) in an Index
$indexer = $factory->buildIndexer();

$dto = new Beer();
$dto->bar = 'American Pale Ale';
$dto->foo = 'Hops from Alsace, France';

// Add a document to the queue
$indexer->scheduleIndex('beers', new Document('123', $dto));
$indexer->flush();

// Set parameters on the Bulk
$indexer->setBulkRequestParams([
    'pipeline' => 'covfefe',
    'refresh' => 'wait_for'
]);

// Force index refresh if needed
$indexer->refresh('beers');

// Get the Document (new!)
$results = $client->getIndex('beers')->getDocument('123');

// Get the DTO (new!)
$results = $client->getIndex('beers')->getModel('123');

// Perform a search
$results = $client->getIndex('beers')->search('alsace');

// Get the Elastic Document
$results->getDocuments()[0];

// Get the Elastica compatible Result
$results->getResults()[0];

// Get the DTO 🎉 (new!)
$results->getResults()[0]->getModel();

// Create a new version of the Index "beers"
$index = $indexBuilder->createIndex('beers');

// Slow down the Refresh Interval of the new Index to speed up indexation
$indexBuilder->slowDownRefresh($index);
$indexBuilder->speedUpRefresh($index);

// Set proper aliases
$indexBuilder->markAsLive($index, 'beers');

// Clean the old indices (close the previous one and delete the older)
$indexBuilder->purgeOldIndices('beers');

// Mapping change? Just call migrate and enjoy a full reindex (use the Task API internally to avoid timeout)
$newIndex = $indexBuilder->migrate($index);
$indexBuilder->speedUpRefresh($newIndex);
$indexBuilder->markAsLive($newIndex, 'beers');
```

*mappings/beers_mapping.yaml*

```yaml
# Anything you want, no validation
settings:
    number_of_replicas: 1
    number_of_shards: 1
    refresh_interval: 60s
mappings:
    dynamic: false
    properties:
        foo:
            type: text
            analyzer: english
            fields:
                keyword:
                    type: keyword
```

## Configuration

This library add custom configurations on top of Elastica's:

### `Factory::CONFIG_MAPPINGS_DIRECTORY` (required with default configuration)

The directory Elastically is going to look for YAML.

When creating a `foobar` index, a `foobar_mapping.yaml` file is expected.

If an `analyzers.yaml` file is present, **all** the indices will get it.

### `Factory::CONFIG_INDEX_CLASS_MAPPING` (required)

An array of index name to class FQN.

```php
[
  'indexName' => My\AwesomeDTO::class,
]
```

### `Factory::CONFIG_MAPPINGS_PROVIDER`

An instance of `MappingProviderInterface`.

If this option is not defined, the factory will fallback to `YamlProvider` and will use
`Factory::CONFIG_MAPPINGS_DIRECTORY` option.

There are two providers available in Elastically: `YamlProvider` and `PhpProvider`.

### `Factory::CONFIG_SERIALIZER` (optional)

A `SerializerInterface` compatible object that will by used on indexation.

_Default to Symfony Serializer with Object Normalizer._

A faster alternative is to use Jane to generate plain PHP Normalizer, see below. Also we recommend [customization to handle things like Date](https://symfony.com/doc/current/components/serializer.html#normalizers).

### `Factory::CONFIG_DENORMALIZER` (optional)

A `DenormalizerInterface` compatible object that will by used on search results to build your objects back.

If this option is not defined, the factory will fallback to
`Factory::CONFIG_SERIALIZER` option.

### `Factory::CONFIG_SERIALIZER_CONTEXT_BUILDER` (optional)

An instance of `ContextBuilderInterface` that build a serializer context from a
class name.

If it is not defined, Elastically, will use a `StaticContextBuilder` with the
configuration from `Factory::CONFIG_SERIALIZER_CONTEXT_PER_CLASS`.

### `Factory::CONFIG_SERIALIZER_CONTEXT_PER_CLASS` (optional)

Allow to specify the Serializer context for normalization and denormalization.

```php
[
    Beer::class => ['attributes' => ['title']],
];
```

_Default to `[]`._

### `Factory::CONFIG_BULK_SIZE` (optional)

When running indexation of lots of documents, this setting allow you to fine-tune the number of document threshold.

_Default to 100._

### `Factory::CONFIG_INDEX_PREFIX` (optional)

Add a prefix to all indexes and aliases created via Elastically.

_Default to `null`._

## Usage in Symfony

### Configuration

You'll need to add the bundle in `bundles.php`:

```php
// config/bundles.php
return [
    // ...
    JoliCode\Elastically\Bridge\Symfony\ElasticallyBundle::class => ['all' => true],
];
```

Then configure the bundle:

```yaml
# config/packages/elastically.yaml
elastically:
    connections:
        default:
            client:
                host:                '%env(ELASTICSEARCH_HOST)%'
                # If you want to use the Symfony HttpClient (you MUST create this service)
                #transport:           'JoliCode\Elastically\Transport\HttpClientTransport'

            # Path to the mapping directory (in YAML)
            mapping_directory:       '%kernel.project_dir%/config/elasticsearch'

            # Size of the bulk sent to Elasticsearch (default to 100)
            bulk_size:               100

            # Mapping between an index name and a FQCN
            index_class_mapping:
                my-foobar-index:     App\Dto\Foobar

            # Configuration for the serializer
            serializer:
                # Fill a static context
                context_mapping:
                    foo:                 bar
```

Finally, inject one of those service (autowirable) in you code where you need
it:

```
JoliCode\Elastically\Client (elastically.default.client)
JoliCode\Elastically\IndexBuilder (elastically.default.index_builder)
JoliCode\Elastically\Indexer (elastically.default.indexer)
```

#### Advanced Configuration

##### Multiple Connections and Autowiring

If you define multiple connections, you can define a default one. This will be
useful for autowiring:

```yaml
elastically:
    default_connection: default
    connections:
        default: # ...
        another: # ...
```

To use class for other connection, you can use *Autowirable Types*. To discover
them, run:

```
bin/console debug:autowiring elastically
```

##### Use a Custom Serializer Context Builder

```yaml
elastically:
    default_connection: default
    connections:
        default:
            serializer:
                context_builder_service: App\Elastically\Serializer\ContextBuilder
                # Do not defined "context_mapping" option anymore
```

##### Use a Custom Mapping provider

```yaml
elastically:
    default_connection: default
    connections:
        default:
            mapping_provider_service: App\Elastically\MappingProvider
            # Do not defined "index_class_mapping" option anymore
```

##### Using HttpClient as Transport

You can also use the Symfony HttpClient for all Elastica communications:

```yaml
JoliCode\Elastically\Transport\HttpClientTransport: ~

JoliCode\Elastically\Client:
    arguments:
        $config:
            host: '%env(ELASTICSEARCH_HOST)%'
            transport: 'JoliCode\Elastically\Transport\HttpClientTransport'
            ...
```

#### Reference

You can run the following command to get the default configuration reference:

```
bin/console config:dump elastically
```

### Using Messenger for async indexing

Elastically ships with a default Message and Handler for Symfony Messenger.

Register the message in your configuration:

```yaml
framework:
    messenger:
        transports:
            async: "%env(MESSENGER_TRANSPORT_DSN)%"

        routing:
            # async is whatever name you gave your transport above
            'JoliCode\Elastically\Messenger\IndexationRequest':  async

services:
    JoliCode\Elastically\Messenger\IndexationRequestHandler: ~
```

The `IndexationRequestHandler` service depends on an implementation of `JoliCode\Elastically\Messenger\DocumentExchangerInterface`, which isn't provided by this library. You must provide a service that implements this interface, so you can plug your database or any other source of truth.

Then from your code you have to call:

```php
use JoliCode\Elastically\Messenger\IndexationRequest;
use JoliCode\Elastically\Messenger\IndexationRequestHandler;

$bus->dispatch(new IndexationRequest(Product::class, '1234567890'));

// Third argument is the operation, so for a delete:
// new IndexationRequest(Product::class, 'ref9999', IndexationRequestHandler::OP_DELETE);
```

And then consume the messages:

```sh
php bin/console messenger:consume async
```

### Grouping IndexationRequest in a spool

Sending multiple `IndexationRequest` during the same Symfony Request is not always appropriate, it will trigger multiple Bulk operations. Elastically provides a Kernel listener to group all the `IndexationRequest` in a single `MultipleIndexationRequest` message.

To use this mechanism, we send the `IndexationRequest` in a memory transport to be consumed and grouped in a really async transport:

```yaml
messenger:
    transports:
        async: "%env(MESSENGER_TRANSPORT_DSN)%"
        queuing: 'in-memory:///'

    routing:
        'JoliCode\Elastically\Messenger\MultipleIndexationRequest': async
        'JoliCode\Elastically\Messenger\IndexationRequest': queuing
```

You also need to register the subscriber:

```yaml
services:
    JoliCode\Elastically\Messenger\IndexationRequestSpoolSubscriber:
        arguments:
            - '@messenger.transport.queuing' # should be the name of the memory transport
            - '@messenger.default_bus'
        tags:
            - { name: kernel.event_subscriber }
```

## Using Jane to build PHP DTO and fast Normalizers

Install [JanePHP](https://jane.readthedocs.io/) json-schema tools to build your own DTO and Normalizers. All you have to do is setting the Jane-completed Serializer on the Factory:

```php
$factory = new Factory([
    Factory::CONFIG_SERIALIZER => $serializer,
]);
```

_[Not compatible with Jane < 6](https://github.com/jolicode/elastically/issues/12)._

## To be done

- some "todo" in the code
- optional Doctrine connector
- better logger - maybe via a processor? extending _log is supposed to be deprecated :(
- extra commands to monitor, update mapping, reindex... Commonly implemented tasks
- optional Symfony integration:
  - web debug toolbar!
- scripts / commands for common tasks:
  - auto-reindex when the mapping change, handle the aliases and everything
  - micro monitoring for cluster / indexes
  - health-check method

## Sponsors

[![JoliCode](https://jolicode.com/images/logo.svg)](https://jolicode.com)

Open Source time sponsored by JoliCode.
