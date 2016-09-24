<?php
namespace PhpSigep\Services\Real;

use PhpSigep\Model\Etiqueta;
use PhpSigep\Model\RastrearObjetoEvento;
use PhpSigep\Model\RastrearObjetoResultado;
use PhpSigep\Services\Real\Exception\RastrearObjeto\RastrearObjetoException;
use PhpSigep\Services\Result;

/**
 * @author: Stavarengo
 */
class RastrearObjeto
{

    /**
     * @param \PhpSigep\Model\RastrearObjeto $params
     * @return \PhpSigep\Services\Result<\PhpSigep\Model\RastrearObjetoResultado[]>
     * @throws Exception\RastrearObjeto\TipoResultadoInvalidoException
     * @throws Exception\RastrearObjeto\TipoInvalidoException
     */
    public function execute(\PhpSigep\Model\RastrearObjeto $params)
    {
        switch ($params->getTipo()) {
            case \PhpSigep\Model\RastrearObjeto::TIPO_LISTA_DE_OBJETOS:
                $tipo = 'L';
                break;
            case \PhpSigep\Model\RastrearObjeto::TIPO_INTERVALO_DE_OBJETOS:
                $tipo = 'F';
                break;
            default:
                throw new \PhpSigep\Services\Real\Exception\RastrearObjeto\TipoInvalidoException("Tipo '" . $params->getTipo(
                    ) . "' não é valido");
                break;
        }
        switch ($params->getTipoResultado()) {
            case \PhpSigep\Model\RastrearObjeto::TIPO_RESULTADO_APENAS_O_ULTIMO_EVENTO:
                $tipoResultado = 'U';
                break;
            case \PhpSigep\Model\RastrearObjeto::TIPO_RESULTADO_TODOS_OS_EVENTOS:
                $tipoResultado = 'T';
                break;
            default:
                throw new \PhpSigep\Services\Real\Exception\RastrearObjeto\TipoResultadoInvalidoException("Tipo de resultado '" . $params->getTipo(
                    ) . "' não é valido");
                break;
        }

        $this->objetos = array_map(function (\PhpSigep\Model\Etiqueta $etiqueta) {
            return $etiqueta->getEtiquetaComDv();
        }, $params->getEtiquetas());

        if (count($this->objetos) == 0) {
            throw new RastrearObjetoException('Erro ao rastrear objetos. Nenhum objeto informado.');
        }

        $soapArgs = array(
            'usuario' => $params->getAccessData()->getUsuario(),
            'senha' => $params->getAccessData()->getSenha(),
            'tipo' => $tipo,
            'resultado' => $tipoResultado,
            'lingua' => 101,
            'objetos' => implode('', $this->objetos)
        );

        $result = new Result();
        
        try {
            $r = SoapClientFactory::getSoapRastro()->buscaEventos($soapArgs);
            if (!$r || !is_object($r) || !isset($r->return) || ($r instanceof \SoapFault)) {
                if ($r instanceof \SoapFault) {
                    throw $r;
                }
                throw new \Exception('Erro ao consultar os dados do cliente. Retorno: "' . $r . '"');
            }
            
            $result->setResult(new RastrearObjetoResultado((array) $r->return));
        } catch (\Exception $e) {
            if ($e instanceof \SoapFault) {
                $result->setIsSoapFault(true);
                $result->setErrorCode($e->getCode());
                $result->setErrorMsg(SoapClientFactory::convertEncoding($e->getMessage()));
            } else {
                $result->setErrorCode($e->getCode());
                $result->setErrorMsg($e->getMessage());
            }
        }
    }

    /**
     * @param $curlResult
     * @throws Exception\RastrearObjeto\RastrearObjetoException
     * @return RastrearObjetoResultado[]
     */
    private function _parseResult($curlResult)
    {
        $result = null;
//        $curlResult = SoapClientFactory::convertEncoding($curlResult);
        $simpleXml = new \SimpleXMLElement($curlResult);
        if ($simpleXml->error) {
            throw new RastrearObjetoException('Erro ao rastrear objetos. Resposta do Correios: "' . $simpleXml->error . '"');
        } else if ($simpleXml->objeto) {
            $qtdObjetos = $simpleXml->qtd;
            $objetos    = $simpleXml->objeto;
            $result    = array();
            for ($i = 0; $i < $qtdObjetos; $i++) {
                $objeto      = $objetos[$i];
                $resultado   = new RastrearObjetoResultado();
                $resultado->setEtiqueta(new Etiqueta(array('etiquetaComDv' => $objeto->numero)));
                foreach ($objeto->evento as $evento) {
                    $dataHoraStr = $evento->data . ' ' . $evento->hora;
                    $dataHora    = \DateTime::createFromFormat('d/m/Y H:i', $dataHoraStr);
                    $tipo = strtoupper($evento->tipo);
                    $status = (int)$evento->status;
                    $descricao = $evento->descricao;
                    $detalhes = null;
                    if ($tipo == 'PO' && $status === 9) {
                        $detalhes = 'Objeto sujeito a encaminhamento no próximo dia útil.';
                    } else if ($evento->destino
                        && (($tipo == 'DO' && in_array($status, array(0, 1, 2)))
                        || ($tipo == 'PMT' && $status === 1)
                        || ($tipo == 'TRI' && $status === 1)
                        || ($tipo == 'RO' && in_array($status, array(0, 1)))
                    )) {
                        $detalhes = 'Objeto encaminhado para ' . $evento->destino->cidade . '/' . $evento->destino->uf;
                        if ($evento->destino->bairro) {
                            $detalhes .= ' - Bairro: ' . $evento->destino->bairro;
                        }
                        if ($evento->destino->local) {
                            $detalhes .= ' - Local: ' . $evento->destino->local;
                        }
                    }

                    $resultado->addEvento(new RastrearObjetoEvento(array(
                        'tipo'      => $tipo,
                        'status'    => $status,
                        'dataHora'  => $dataHora,
                        'descricao' => $descricao,
                        'detalhes'  => $detalhes,
                        'local'     => $evento->local,
                        'codigo'    => $evento->codigo,
                        'cidade'    => $evento->cidade,
                        'uf'        => $evento->uf,
                    )));
                }
                
                $result[] = $resultado;
            }
        }
        
        return $result;
    }

}
