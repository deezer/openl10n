<?php

namespace Openl10n\Bundle\ApiBundle\Tests\Controller;

use Openl10n\Bundle\ApiBundle\Test\WebTestCase;
use Openl10n\Domain\Translation\Value\Hash;
use Openl10n\Value\Localization\Locale;
use Openl10n\Value\String\Slug;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ResourceControllerTest extends WebTestCase
{
    public function testGetResourceList()
    {
        $client = $this->getClient();
        $client->jsonRequest('GET', '/api/resources?project=foobar');

        $data = $this->assertJsonResponse(
            $client->getResponse(),
            Response::HTTP_OK
        );

        $this->assertCount(3, $data, 'There should be 3 resources in "foobar" project');
    }

    public function testGetResource()
    {
        $client = $this->getClient();
        $client->jsonRequest('GET', '/api/resources/1');

        $data = $this->assertJsonResponse(
            $client->getResponse(),
            Response::HTTP_OK,
            'resource'
        );

        $resource = $this->get('openl10n.repository.resource')->findOneById(1);

        $this->assertNotNull($resource, 'Resource #1 should exist. Unless there is a problem with the fixtures.');
        $this->assertEquals((string) $resource->getProject()->getSlug(), $data->project);
        $this->assertEquals((string) $resource->getPathname(), $data->pathname);
    }

    public function testCreateNewResource()
    {
        $client = $this->getClient();
        $client->jsonRequest('POST', '/api/resources', [
            'project' => 'foobar',
            'pathname' => 'new.en.yml',
        ]);

        $data = $this->assertJsonResponse(
            $client->getResponse(),
            Response::HTTP_CREATED,
            'resource'
        );

        // Ensure resource has been created
        $resource = $this->get('openl10n.repository.resource')->findOneById($data->id);

        $this->assertNotNull($resource, 'New resource should have been created');
        $this->assertEquals((string) $resource->getPathname(), 'new.en.yml');
    }

    public function testCreateExistingResource()
    {
        $this->markTestIncomplete('Current POST request throw a 500 due to a duplicate key');

        $client = $this->getClient();
        $client->jsonRequest('POST', '/api/resources', [
            'project' => 'foobar',
            'pathname' => 'app/Resources/translations/messages.en.yml',
        ]);

        $this->assertJsonResponse(
            $client->getResponse(),
            Response::HTTP_BAD_REQUEST
        );
    }

    public function testUpdateResource()
    {
        $client = $this->getClient();
        $client->jsonRequest('PUT', '/api/resources/1', [
            'pathname' => 'update.en.yml',
        ]);

        $this->assertJsonResponse(
            $client->getResponse(),
            Response::HTTP_NO_CONTENT
        );

        // Ensure resource has been created
        $resource = $this->get('openl10n.repository.resource')->findOneById(1);

        $this->assertNotNull($resource, 'Resource should still exist');
        $this->assertEquals((string) $resource->getPathname(), 'update.en.yml');
    }

    public function testDeleteResource()
    {
        $this->markTestIncomplete('Not implemented yet');

        $client = $this->getClient();
        $client->jsonRequest('DELETE', '/api/resources/1');

        $this->assertJsonResponse(
            $client->getResponse(),
            Response::HTTP_NO_CONTENT
        );

        $this->assertNull(
            $this->get('openl10n.repository.resource')->findOneBySlug(1),
            'Resource should not exist after a DELETE'
        );
    }

    public function testImportResource()
    {
        // Retrieve id of the "empty" resource in foobar project
        $emptyResource = $this->getResource('foobar', 'empty.en.json');
        $resourceId = $emptyResource->getId();

        // Create dummy json file
        $tempname = sys_get_temp_dir().'/'.mt_rand(0, 9999).'_dummy.en.json';
        $content = json_encode([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ]);
        file_put_contents($tempname, $content);
        $uploadedFile = new UploadedFile(
            $tempname,
            'dummy.en.json',
            'application/json',
            strlen($content)
        );

        $client = $this->getClient();

        // Upload resource file
        $client->request(
            'POST',
            '/api/resources/'.$resourceId.'/import',
            ['locale' => 'en'],
            ['file' => $uploadedFile]
        );

        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        // Here I'm force to clear the EntityManager to be sure the `Key::getPhrase()`
        $this->get('doctrine.orm.entity_manager')->clear();

        // Assert content has been inserted in database
        $translationKeys = $this->get('openl10n.repository.translation')->findByResource($emptyResource);
        $this->assertCount(3, $translationKeys);

        $this->assertEquals('key1', $translationKeys[0]->getIdentifier());
        $this->assertEquals('key2', $translationKeys[1]->getIdentifier());
        $this->assertEquals('key3', $translationKeys[2]->getIdentifier());

        $this->assertEquals('value1', $translationKeys[0]->getPhrase(Locale::parse('en'))->getText());
        $this->assertEquals('value2', $translationKeys[1]->getPhrase(Locale::parse('en'))->getText());
        $this->assertEquals('value3', $translationKeys[2]->getPhrase(Locale::parse('en'))->getText());
    }

    public function testImportResourceWithExistingKeys()
    {
        // Retrieve id of the "empty" resource in foobar project
        $emptyResource = $this->getResource('foobar', 'app/Resources/translations/messages.en.yml');
        $resourceId = $emptyResource->getId();

        // Create dummy json file
        $tempname = sys_get_temp_dir().'/'.mt_rand(0, 9999).'_dummy.en.json';
        $content = json_encode([
            'example.key1' => 'new value1',
            'example.key2' => 'new value1',
        ]);
        file_put_contents($tempname, $content);
        $uploadedFile = new UploadedFile(
            $tempname,
            'dummy.en.json',
            'application/json',
            strlen($content)
        );

        $client = $this->getClient();

        // Upload resource file
        $client->request(
            'POST',
            '/api/resources/'.$resourceId.'/import',
            ['locale' => 'en'],
            ['file' => $uploadedFile]
        );

        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        // Here I'm force to clear the EntityManager to be sure the `Key::getPhrase()`
        $this->get('doctrine.orm.entity_manager')->clear();

        // Assert content has been inserted in database
        $translationKeys = $this->get('openl10n.repository.translation')->findByResource($emptyResource);
        $this->assertGreaterThanOrEqual(2, $translationKeys);

        $this->assertEquals('example.key1', $translationKeys[0]->getIdentifier());
        $this->assertEquals('example.key2', $translationKeys[1]->getIdentifier());

        $this->assertNotEquals('value1', $translationKeys[0]->getPhrase(Locale::parse('en'))->getText());
        $this->assertNotEquals('value2', $translationKeys[1]->getPhrase(Locale::parse('en'))->getText());
    }

    public function testImportResourceWithVeryLongKey()
    {
        // Retrieve id of the "empty" resource in foobar project
        $emptyResource = $this->getResource('foobar', 'empty.en.json');
        $resourceId = $emptyResource->getId();

        $veryLongKey = str_repeat('Very Long Key.', 500);
        $veryLongValue = str_repeat('Very Long Value.', 500);

        // Create dummy json file
        $tempname = sys_get_temp_dir().'/'.mt_rand(0, 9999).'_dummy.en.json';
        $content = json_encode([$veryLongKey => $veryLongValue]);
        file_put_contents($tempname, $content);
        $uploadedFile = new UploadedFile(
            $tempname,
            'dummy.en.json',
            'application/json',
            strlen($content)
        );

        $client = $this->getClient();

        // Upload resource file
        $client->request(
            'POST',
            '/api/resources/'.$resourceId.'/import',
            ['locale' => 'en'],
            ['file' => $uploadedFile]
        );

        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        // Here I'm force to clear the EntityManager to be sure the `Key::getPhrase()`
        $this->get('doctrine.orm.entity_manager')->clear();

        // Assert content has been inserted in database
        $translationKeys = $this->get('openl10n.repository.translation')->findByResource($emptyResource);
        $this->assertCount(1, $translationKeys);

        $this->assertNotEquals($veryLongKey, $translationKeys[0]->getIdentifier(), 'The key is truncated in database');

        $expectedHash = (string) new Hash($veryLongKey);
        $this->assertEquals($expectedHash, $translationKeys[0]->getHash());
        $this->assertEquals($veryLongValue, $translationKeys[0]->getPhrase(Locale::parse('en'))->getText());
    }

    public function testImportMalFormattedJsonResource()
    {
        // Retrieve id of the "empty" resource in foobar project
        $emptyResource = $this->getResource('foobar', 'empty.en.json');
        $resourceId = $emptyResource->getId();

        // Create a malformatted json file
        $tempname = sys_get_temp_dir().'/'.mt_rand(0, 9999).'_dummy.en.json';
        $content = '{"key": "value", }';
        file_put_contents($tempname, $content);
        $uploadedFile = new UploadedFile(
            $tempname,
            'dummy.en.json',
            'application/json',
            strlen($content)
        );

        $client = $this->getClient();

        // Upload resource file
        $client->request(
            'POST',
            '/api/resources/'.$resourceId.'/import',
            ['locale' => 'en'],
            ['file' => $uploadedFile]
        );

        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @dataProvider dataProviderExportFormat
     */
    public function testExportResource($format)
    {
        $url = '/api/resources/1/export?locale=en';
        if (null !== $format) {
            $url .= '&format='.$format;
        }

        $client = $this->getClient();
        $client->request('GET', $url);

        $response = $client->getResponse();
        $content = trim(file_get_contents($response->getFile()->getPathname()));

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals($this->getExportContent($format), $content);
    }

    public function dataProviderExportFormat()
    {
        return [
            [null],
            ['yml'],
            ['ini'],
            ['csv'],
            ['json'],
            ['xlf'],
        ];
    }

    private function getResource($projectSlug, $resourcePathname)
    {
        $project = $this->get('openl10n.repository.project')->findOneBySlug(new Slug($projectSlug));
        $resources = $this->get('openl10n.repository.resource')->findByProject($project);
        $resource = array_filter($resources, function($resource) use ($resourcePathname) {
            return $resourcePathname === (string) $resource->getPathname();
        });

        $resource = end($resource);

        return $resource;
    }

    /**
     * Get export content of resource 1 in different format
     *
     * @param string $format The format of the export
     *
     * @return string The content as text
     */
    private function getExportContent($format)
    {
        switch ($format) {
            case 'ini':
                return <<<EOF
example.key1="This is a first example"
example.key2="This is a second example"
EOF;

            case 'csv':
                return <<<EOF
example.key1;"This is a first example"
example.key2;"This is a second example"
EOF;

            case 'json':
                return <<<EOF
{
    "example.key1": "This is a first example",
    "example.key2": "This is a second example"
}
EOF;

            case 'xlf':
                return <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en" datatype="plaintext" original="file.ext">
    <body>
      <trans-unit id="03fdeaf5007f7e942ebcf2d1a5803b84" resname="example.key1">
        <source>example.key1</source>
        <target>This is a first example</target>
      </trans-unit>
      <trans-unit id="a4cfdcd4f5cd9e1d18fc4aef6fcac406" resname="example.key2">
        <source>example.key2</source>
        <target>This is a second example</target>
      </trans-unit>
    </body>
  </file>
</xliff>
EOF;

            // default format is Yaml
            case 'yml':
            default:
                return <<<EOF
example.key1: 'This is a first example'
example.key2: 'This is a second example'
EOF;
        }
    }
}
