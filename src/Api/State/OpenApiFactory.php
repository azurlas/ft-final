<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\{OpenApi,Model};
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates:'api_platform.openapi.factory')]
final readonly class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $inner)
    {
    }
    public function __invoke(array $context = []): OpenApi
    {
        $api = ($this->inner)($context);
        $id = new Model\Parameter(name:'id', in:'path', required:true, schema:['type' => 'integer']);
        $security = [['JWT' => []]];
        $api->getPaths()->addPath('/api/assignments/{id}/submit', new Model\PathItem(post:new Model\Operation(operationId:'submitAssignment', tags:['Submission'], summary:'Submit exactly one file, any format, at most 10,000,000 bytes', parameters:[$id], security:$security, requestBody:new Model\RequestBody(required:true, content:new \ArrayObject(['multipart/form-data' => ['schema' => ['type' => 'object','required' => ['file'],'additionalProperties' => false,'properties' => ['file' => ['type' => 'string','format' => 'binary']]]]])), responses:['201' => new Model\Response('Submission metadata'),'400' => new Model\Response('Invalid multipart or size'),'403' => new Model\Response('Enrollment required'),'409' => new Model\Response('Deadline passed or already submitted')])));
        foreach (['/api/submissions/{id}/download' => ['downloadSubmission','Submission','application/octet-stream'],'/api/courses/{id}/report' => ['exportCourseReport','Report','application/pdf']] as $path => [$operationId,$tag,$mime]) {
            $api->getPaths()->addPath($path, new Model\PathItem(get:new Model\Operation(operationId:$operationId, tags:[$tag], summary:$tag === 'Report' ? 'Administrator PDF report by students' : 'Owner or administrator private download', parameters:[$id], security:$security, responses:['200' => new Model\Response('Binary document', content:new \ArrayObject([$mime => ['schema' => ['type' => 'string','format' => 'binary']]])),'403' => new Model\Response('Forbidden'),'404' => new Model\Response('Not found')])));
        }
        $path = '/api/courses/{id}/purchase';
        $item = $api->getPaths()->getPath($path);
        if ($item?->getPost()) {
            $api->getPaths()->addPath($path, $item->withPost($item->getPost()->withParameters([...($item->getPost()->getParameters() ?? []),new Model\Parameter(name:'Idempotency-Key', in:'header', required:true, description:'8–128 characters. Replay returns the same purchase; changed body conflicts.', schema:['type' => 'string'])])->withDescription('Asynchronous mock payment. Currency and amount come from the course. Use only documented synthetic fixture cards, expiry 12/2035, CVV 123. Two retries follow the initial attempt for transient failures.')));
        }
        return $api;
    }
}
