<?php

namespace App\Http\Controllers\Api;

use App\API\ApiError;
use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use App\Models\Feedback;
use App\Facades\ApiFeedback;
use App\Models\Requisicao;
use App\Models\Instituicao;
use Illuminate\Support\Facades\Http;

class FeedbackController extends Controller {
    const DOMINIO_SITE = [
        'imbe' => 'gmob.imbe.rs.gov.br',
        'tramandai' => 'tramandai.gmob.app.br'
    ];
    /**
     * Display a listing of the resource.
     */
    public function index() {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function create(Request $request) {
        $registros = [];
        $feedBack = [
            'ocorrenciaID' => intval($request->ocorrenciaID),
            'chaveAcesso' => $request->chaveAcesso,
            'horarioDespachoID' => $request->horarioDespachoID,
            'sistemaOrigem' => $request->sistemaOrigem
        ];


        foreach ($request->pessoas as $pessoa) {
            if (strlen($pessoa['telefone']) < 8 || strlen($pessoa['telefone']) > 11) {
                continue;
            }
            $pessoas = [
                'pessoaID' => $pessoa['pessoaID'],
                'nome' => $pessoa['nome'],
                'telefone' => $this->formataTelefone($pessoa['telefone']),
                'condicao' => $pessoa['condicao'],
                'situacaoEnvio' => 'pendente',
            ];
            $dados = array_merge($feedBack, $pessoas);
            $feedback = Feedback::create($dados);
            $registros[$pessoa['pessoaID']] =  $feedback->id;
        }

        return ['msg' => 'Registros salvos e adicionado a fila.', 'error' => false, 'dados' => $registros];
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id) {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id) {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request) {
        $recebeFeedback = Feedback::find($request->id);
        $recebeFeedback->delete();

        return redirect()->route('feedback')->with('success', 'Feedback apagado com sucesso!');
    }

    public function destroyInstituicao(Request $request) {
        $instituicao = Instituicao::find($request->id);
        $instituicao->delete();

        return redirect()->route('instituicao')->with('success', 'Instituicao apagado com sucesso!');
    }

    public function enviarFeedbackManual(Request $request) {
        $recebeFeedback = Feedback::find($request->id);
        if ($recebeFeedback->situacaoEnvio != 'pendente') {
            return redirect()->route('feedback')->with('danger', 'Situação não permitida para envio');
        }
        if ($this->validaTelefoneWhatsapp($recebeFeedback['telefone'])) {
            $instituicao = Instituicao::where('cidade', '=', $recebeFeedback->sistemaOrigem)->first();
            $guarda = $instituicao->guarda;
            $cidade = $instituicao->cidade;
            $parseMessage = $this->messageFeedback($recebeFeedback, $guarda, $cidade);
            $response = json_decode(ApiFeedback::post('', $parseMessage), true);

            if (isset($response['error'])) {
                $this->logarRequisicao($recebeFeedback->telefone, 'envio-manual', $parseMessage, $response);
            } else {
                Feedback::find($request->id)->update(['situacaoEnvio' => 'enviado', 'mensagemID' => $response['messages'][0]['id']]);

                return redirect()->route('feedback')->with('success', 'Feedback enviado com sucesso!');
            }
        } else {
            Feedback::find($request->id)->update(['situacaoEnvio' => 'numeroInvalido']);

            return redirect()->route('feedback')->with('danger', 'Numero Inválido');
        }
    }

    public function cron(Request $request) {
        $identificadorGuarda = $request->identificador;
        $guarda = '';
        $cidade = '';

        if ($this->validarGuarda($identificadorGuarda) === true) {
            $pessoas = Feedback::select()
                ->where('situacaoEnvio', '=', 'pendente')
                ->where('sistemaOrigem', '=', $identificadorGuarda)
                ->get();

            $instituicao = Instituicao::where('cidade', '=', $identificadorGuarda)->first();
            $guarda = $instituicao->guarda;
            $cidade = $instituicao->cidade;

            foreach ($pessoas as $pessoa) {
                if ($this->validaTelefoneWhatsapp($pessoa['telefone'])) {
                    $parseMessage = $this->messageFeedback($pessoa, $guarda, $cidade);
                    $response = json_decode(ApiFeedback::post('', $parseMessage), true);

                    if (isset($response['error'])) {
                        $this->logarRequisicao($pessoa->telefone, 'cronError', $parseMessage, $response);
                        continue;
                    }
                    Feedback::find($pessoa->id)->update(['situacaoEnvio' => 'enviado', 'mensagemID' => $response['messages'][0]['id']]);
                } else {
                    Feedback::find($pessoa->id)->update(['situacaoEnvio' => 'numeroInvalido']);
                }
            }
        } else {
            return ApiError::errorMessage('Não foi possivel validar a requisição', 'cron', true);
        }
    }

    private function validaTelefoneWhatsapp($telefone) {
        return true;

        https: //graph.facebook.com/{{Version}}/{{WABA-ID}}/phone_numbers
    }

    public function cronTransmitir(Request $request) {
        $identificadorGuarda = $request->identificador;

        if ($this->validarGuarda($identificadorGuarda) === true) {
            $feedback = Feedback::select('ocorrenciaID', 'pessoaID', 'avaliacao', 'observacao')
                ->where('situacaoEnvio', '=', 'recebido')
                ->where('sistemaOrigem', '=', $identificadorGuarda)
                ->get();

            $dados = [];
            foreach ($feedback as $row) {
                $dados[] = [
                    'ocorrenciaID' => $row->ocorrenciaID,
                    'pessoaID' => $row->pessoaID,
                    'avaliacao' => $row->avaliacao,
                    'observacao' => $row->observacao
                ];
            }
            if (empty($dados)) {
                return;
            }

            $dominioSite = self::DOMINIO_SITE[$identificadorGuarda];
            $response = json_decode(Http::post('https://' . $dominioSite . '/feedback', ['data' => $dados]), true);
            $this->logarRequisicao('', 'cronTransmitir', $dados, $response);
            if ($response['status'] != 'error') {
                foreach ($dados as $pessoa) {
                    Feedback::where('ocorrenciaID', $pessoa['ocorrenciaID'])
                        ->where('pessoaID', $pessoa['pessoaID'])
                        ->update(['situacaoEnvio' => 'transmitido']);
                }
            }

            return $response;
        }
    }

    private function messageFeedback($data, $guarda = '',  $cidade = '') {
        $messageFeedback = [
            "messaging_product" => "whatsapp",
            "to" => "55" . $data['telefone'],
            "type" => "template",
            "template" => [
                "name" => "sigesp_atendimento_boletim",
                "language" => [
                    "code" => "pt_BR"
                ],
                "components" => array(
                    [
                        "type" => "header",
                        "parameters" => [
                            [
                                "type" => "text",
                                "text" => $guarda
                            ]
                        ]
                    ],
                    [
                        "type" => "body",
                        "parameters" => [
                            [
                                "type" => "text",
                                "text" => $data['nome']
                            ],
                            [
                                "type" => "text",
                                "text" => strval($data['ocorrenciaID'])
                            ],
                            [
                                "type" => "text",
                                "text" => strval($data['chaveAcesso'])
                            ]
                        ]
                    ]
                )
            ]
        ];

        return $messageFeedback;
    }

    public function retorno(Request $request) // criar futuramente a opção de colocar uma observação
    {
        $telefone = $request->telefone;
        $avaliacao = $request->avaliacao;

        $idPessoa = Feedback::select('id', 'sistemaOrigem')
            ->where('telefone', 'LIKE', '%' . substr($telefone, -8) . '%')
            ->where('situacaoEnvio', '=', 'enviado')
            ->orderBy('id', 'DESC')
            ->first();

        if (empty($idPessoa)) {
            return ApiError::errorMessage('Número não encontrado no atendimento ou já foi realizado a avaliação do atendimento.', 'telefone', true);
        }

        if (strlen($avaliacao) > 1 && !$this->verificaOpcoesValida($avaliacao)) {
            $this->logarRequisicao($telefone, 'WebhookRetorno', $avaliacao, '');
            return ApiError::errorMessage('Opção inválida, digite novamente um valor referente a avaliação do atendimento.', 'mensagem', true);
        }

        Feedback::where('id', $idPessoa->id)->update(['situacaoEnvio' => 'recebido', 'avaliacao' => $avaliacao]);

        return ['msg' => 'Obrigado pela avaliação! Fazemos sempre o nosso melhor para atender a todos da melhor forma possível.', 'error' => false, 'sistemaOrigem' => $idPessoa->sistemaOrigem];
    }

    private function verificaOpcoesValida($avaliacao) {
        $opcoesValidas = ['1', '2', '3', '4', '5'];

        return in_array($avaliacao, $opcoesValidas);
    }

    public function formataTelefone(string $telefone): string {
        $telefoneFormatado = $telefone;
        $quantidadeDigitos = strlen($telefone);
        if ($quantidadeDigitos === 8) {
            $telefoneFormatado = '519' . $telefone;
        }
        if ($quantidadeDigitos === 9) {
            $telefoneFormatado = '51' . $telefone;
        }


        return $telefoneFormatado;
    }

    private function logarRequisicao($telefoneEnviado, $servicoOrigem, $messagem, $retorno) {
        $salvar = new Requisicao;
        $salvar->telefone = $telefoneEnviado;
        $salvar->origem = $servicoOrigem;
        $salvar->template = isset($messagem['type']) ? $messagem['type'] : '';
        $salvar->messagem = json_encode($messagem);
        $salvar->response = json_encode($retorno);
        $salvar->save();
    }

    public function criaInstituicao(Request $request) {
        $data = [
            "identificador" => isset($request->identificador) ? $request->identificador : '',
            "guarda" => $request->guarda,
            "cidade" => $request->cidade,
            "whatsapp" => $request->whatsapp,
            "permiteBoletimOnline" => $request->permiteBoletimOnline
        ];
        if ($data['identificador']) {
            $instituicao = Instituicao::find($data['identificador']);
        } else {
            $instituicao = Instituicao::where('cidade', '=', $data['cidade'])->first();
        }

        if (empty($instituicao)) {
            $instituicao = new Instituicao;
        }
        $instituicao->guarda = $data['guarda'];
        $instituicao->cidade = $data['cidade'];
        $instituicao->whatsapp = $data['whatsapp'];
        $instituicao->permiteBoletimOnline = $data['permiteBoletimOnline'];
        $instituicao->save();

        return ['msg' => 'Registro salvo da instituição', 'error' => false];
    }

    private function validarGuarda(string $identificadorGuarda) {
        return array_key_exists($identificadorGuarda, self::DOMINIO_SITE);
    }
}
