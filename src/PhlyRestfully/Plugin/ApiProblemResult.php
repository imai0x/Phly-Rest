<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfully\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Create a result payload compatible with the Problem API.
 *
 * @todo   Allow adding/modifying problem status titles
 * @see    http://tools.ietf.org/html/draft-nottingham-http-problem-02
 */
class ApiProblemResult extends AbstractPlugin
{
    /**
     * Indicate whether or not the detail should include a stack trace, if
     * an exception was provided.
     * 
     * @var bool
     */
    protected $detailIncludesStackTrace = false;

    /**
     * Status titles for common problems
     *
     * @var array
     */
    protected $problemStatusTitles = array(
        404 => 'Not Found',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
    );

    /**
     * Indicate whether the detail should include a stack trace, if an
     * exception was provided as the detail.
     * 
     * @param  bool $flag 
     */
    public function setDetailIncludesStackTrace($flag)
    {
        $this->detailIncludesStackTrace = (bool) $flag;
    }

    /**
     * Create a Problem API result
     *
     * @param  int $httpStatus
     * @param  string $detail
     * @param  string $describedBy
     * @param  string $title
     * @return array
     */
    public function generate($httpStatus, $detail, $describedBy = 'http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html', $title = 'Unknown')
    {
        if ($title == 'Unknown'
            && $describedBy == 'http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html'
            && array_key_exists($httpStatus, $this->problemStatusTitles)
        ) {
            $title = $this->problemStatusTitles[$httpStatus];
        }

        if ($detail instanceof \Exception) {
            $detail = $this->createDetailFromException($detail);
        }

        $controller = $this->getController();
        $response   = $controller->getResponse();
        $response->setStatusCode($httpStatus);

        $result = compact('describedBy', 'title', 'httpStatus', 'detail');
        return $result;
    }

    /**
     * Invokable form of class
     *
     * @param  int $httpStatus
     * @param  string $detail
     * @param  string $describedBy
     * @param  string $title
     * @return array
     */
    public function __invoke($httpStatus, $detail, $describedBy = 'http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html', $title = 'Unknown')
    {
        return $this->generate($httpStatus, $detail, $describedBy, $title);
    }

    protected function createDetailFromException(\Exception $e)
    {
        if (!$this->detailIncludesStackTrace) {
            return $e->getMessage();
        }
        $message = '';
        do {
            $message .= $e->getMessage() . "\n";
            $message .= $e->getTraceAsString() . "\n";
            $e = $e->getPrevious();
        } while ($e instanceof \Exception);
        return trim($message);
    }
}
