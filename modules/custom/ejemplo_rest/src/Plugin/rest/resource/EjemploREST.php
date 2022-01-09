<?php

namespace Drupal\ejemplo_rest\Plugin\rest\resource;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Render\RenderContext;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Un ejemplo de recurso REST.
 *
 * @RestResource(
 *   id = "ejemplo_rest",
 *   label = @Translation("Ejemplo REST"),
 *   uri_paths = {
 *     "canonical" = "/rest/ejemplo"
 *   }
 * )
 */
class EjemploREST extends ResourceBase {

  use StringTranslationTrait;

  /**
   * Método get().
   *
   * @return \Drupal\rest\ResourceResponse
   */
  public function get(Request $request) : ResourceResponse {
    // #1 - Debe de ser posible filtrar por node_id individual, node_id multiple o "all" (restituyendo todos los id).
    $node_ids = $request->get('node_ids');

    // #3 - Añadir exception si no viene pasado el parámetro node_id(aquello que se usa para filtrar los contenidos por id)
    if (empty ($node_ids)) {
      throw new BadRequestHttpException($this->t('You must provide the nodes to get.'));
    }

    // Esto es limpieza general. "One is a special case of many".
    if (!is_array($node_ids)) {
      if ($node_ids == 'all') {
        $node_ids = [];
      }
      else {
        $node_ids = [$node_ids];
      }
    }

    // Limpieza.
    // #5 - Añadir controles de validación para el tipo de dato pasado en query param.
    // #7 - Controlar que no hayan caracteres especiales en el interno de los datos pasados en query param.
    foreach ($node_ids as &$id) {
      $clean = intval(Xss::filter($id));
      if (!$clean) {
        throw new BadRequestHttpException($this->t('Invalid value "@value".', ['@value' => $id]));
      }
      $id = $clean;
    }

    // #2 - Devolver solo los contenidos en lengua inglesa y añadir un query param "lang" para filtar por lenguas diferentes.
    $lang = $request->get('lang');
    if (empty($lang)) {
      $lang = 'en';
    }

    // Esto no es lo ideal en un servicio, lo mejor es usar inyección de dependencias en el constructor y agregar
    // el EntityTypeManagerInterface para poder hacer $this->entityTypeManager->... .
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery();
    $query
      ->condition('type', 'article')
      ->condition('status', 1)
      ->condition('field_idioma', $lang);

    // #1
    if (!empty($node_ids)) {
      $query->condition('nid', $node_ids, 'IN');
    }

    // Asumimos que no van a ser tantos y por eso cargamos todos a memoria. Dependiendo del escenario esto es mala
    // práctica.
    $found = $query->execute();
    if (!empty($found)) {
      $found = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($found);
    }

    #4 - Añadir exception cuando non se encuentran contenidos con el id pedido(ejemplo contenido no encontrado 404)
    $diff = array_diff($node_ids, array_keys($found));
    if (!empty($diff)) {
      throw new NotFoundHttpException(t('Cannot found nodes: @nodes.', ['@nodes' => join(', ', $diff)]));
    }

    // Se utiliza el renderer y un listado de caches que se deben agregar a la respuesta.
    $context = new RenderContext();
    $renderer = \Drupal::service('renderer');
    $caches = [];
    foreach ($found as $node) {
      $caches[] = CacheableMetadata::createFromObject($node);
      $body = $renderer->executeInRenderContext($context, function () use ($renderer, $node) {
        $body = $node->body->view('full');
        return $renderer->render($body);
      });
      $data[] = [
        'titulo' => $node->label(),
        'contenido' => trim($body),
      ];
    }

    // Resultado para la respuesta.
    // #8 - Ademas de message y data devolver un campo time en formato timestamp que indique el moemnto en el que viene seguida la llamada.
    $result = [
      'message' => '',
      'data' => $data,
      'time' => (new DrupalDateTime())->format('U'),
    ];

    // Respuesta con los datos de cache.
    $response = new ResourceResponse($result);
    $response->addCacheableDependency(['#cache' => ['context' => ['url.path.query_args']]]);
    foreach ($caches as $cache) {
      $response->addCacheableDependency($cache);
    }
    return $response;
  }

}
