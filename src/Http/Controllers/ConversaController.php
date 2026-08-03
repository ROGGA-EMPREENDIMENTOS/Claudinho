<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Rogga\Claudinho\Confirmacao;
use Rogga\Claudinho\Contracts\ResolvedorDeUsuario;
use Rogga\Claudinho\Conversa;
use Rogga\Claudinho\Models\ConversaExterna;
use RuntimeException;
use Throwable;

/**
 * O Claudinho por HTTP, para WhatsApp e afins.
 *
 * O ponto central: este controller autentica como o usuário que o resolver da
 * aplicação devolver, e a partir daí o pacote inteiro funciona igual ao chat em
 * tela — gates das ferramentas, Global Scopes por obra, nome no system prompt. Não
 * existe caminho alternativo de permissão aqui, e é de propósito: um endpoint com
 * o próprio critério de acesso seria uma segunda verdade para manter em sincronia.
 */
class ConversaController
{
    public function conversar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'canal' => ['required', 'string', 'max:40'],
            'identificador' => ['required', 'string', 'max:190'],
            'mensagem' => ['required', 'string', 'max:4000'],
        ]);

        $usuario = $this->usuario($dados['canal'], $dados['identificador']);

        $registro = ConversaExterna::emAndamento(
            $dados['canal'],
            $dados['identificador'],
            (int) $usuario->getAuthIdentifier()
        );

        $motor = $this->motor($registro);

        // Conversa pausada: a mensagem que chega é a DECISÃO da ação proposta, não
        // uma pergunta nova. Tratar como pergunta deixaria a pendência aberta e a
        // conversa travada, porque a API exige tool_result para todo tool_use.
        return $motor->pausada()
            ? $this->decidir($registro, $motor, $dados['mensagem'])
            : $this->perguntar($registro, $motor, $dados['mensagem']);
    }

    /**
     * Começa uma conversa nova, descartando o histórico. Útil no "recomeçar" do
     * menu do bot, e para o usuário sair de um contexto que azedou.
     */
    public function reiniciar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'canal' => ['required', 'string', 'max:40'],
            'identificador' => ['required', 'string', 'max:190'],
        ]);

        // Resolve mesmo sem usar o usuário: número desconhecido não deve descobrir
        // se existe conversa para ele.
        $this->usuario($dados['canal'], $dados['identificador']);

        ConversaExterna::query()
            ->where('canal', $dados['canal'])
            ->where('identificador', $dados['identificador'])
            ->delete();

        return response()->json([
            'resposta' => 'Conversa reiniciada.',
            'estado' => 'reiniciada',
            'confirmacao' => null,
        ]);
    }

    private function perguntar(ConversaExterna $registro, Conversa $motor, string $mensagem): JsonResponse
    {
        $motor->perguntar($mensagem);

        return $this->rodar($registro, $motor);
    }

    /**
     * Interpreta a decisão sobre a ação pendente.
     *
     * Duas escolhas conservadoras aqui, e as duas são deliberadas:
     *
     * 1. Só aprovação EXATA aprova. "sim" aprova; "sim, pode cancelar" não. Casar
     *    por conteúdo faria "não, não confirmo" conter "confirmo" e autorizar o
     *    oposto do pedido. A resposta diz exatamente o que digitar, então a
     *    exigência é justa.
     * 2. Qualquer outra coisa RECUSA, em vez de deixar pendente. Pendência viva
     *    espera um "sim" que pode chegar em outro assunto, meia hora depois.
     */
    private function decidir(ConversaExterna $registro, Conversa $motor, string $mensagem): JsonResponse
    {
        $pendentes = $motor->pendentes();

        $motivo = match (true) {
            // Prazo próprio, mais curto que o da conversa: um "sim" solto tempo
            // depois não pode autorizar alteração que a pessoa já esqueceu.
            $registro->confirmar_ate === null || $registro->confirmar_ate->isPast() => 'O prazo para confirmar '
                .'expirou e a alteração foi cancelada. Se ainda quiser, peça de novo.',

            // Uma frase de texto não distingue "sim" para qual das duas.
            count($pendentes) > 1 => 'Este canal não confirma mais de uma alteração por vez. As alterações '
                .'foram canceladas — peça uma de cada vez.',

            Confirmacao::aprovada($mensagem) => null,

            default => 'A alteração foi cancelada porque a resposta não foi exatamente "SIM". '
                .'Nada foi alterado.',
        };

        $livre = $motivo === null
            ? $motor->resolver($pendentes[0]['id'], aprovada: true)
            : $motor->recusarTudo();

        // Recusa deixa o modelo comentar; aprovação também. Se por algum motivo a
        // conversa não ficou livre, não roda o loop — haveria tool_use sem par.
        return $this->rodar($registro, $motor, prefixo: $motivo, rodarLoop: $livre);
    }

    /**
     * Roda o loop, grava o estado e monta a resposta. O estado é gravado mesmo em
     * caso de falha: o motor fecha os tool_use abertos antes de propagar, e é essa
     * gravação que impede a conversa de ficar inutilizável.
     */
    private function rodar(
        ConversaExterna $registro,
        Conversa $motor,
        ?string $prefixo = null,
        bool $rodarLoop = true
    ): JsonResponse {
        $erro = null;

        if ($rodarLoop) {
            try {
                $motor->responder();
            } catch (Throwable $th) {
                $erro = $th->getMessage();
            }
        }

        $pendentes = $motor->pendentes();
        $pausada = $motor->pausada();

        $registro->confirmar_ate = $pausada
            ? now()->addMinutes(max(1, (int) config('claudinho.api.minutos_confirmacao', 5)))
            : null;

        $registro->guardar($motor);

        if ($erro !== null) {
            return response()->json([
                'resposta' => 'Não foi possível responder agora: '.$erro,
                'estado' => 'erro',
                'confirmacao' => null,
            ], 502);
        }

        return response()->json([
            'resposta' => $this->texto($motor, $prefixo, $pendentes),
            'estado' => $pausada ? 'aguardando_confirmacao' : 'concluida',
            'confirmacao' => $pausada ? $pendentes[0]['confirmacao'] : null,
            'expira_em' => $registro->expira_em?->toIso8601String(),
        ]);
    }

    /**
     * O texto pronto para reenviar ao canal. A maioria das integrações só repassa
     * isto; `confirmacao` fica separado no JSON para quem quiser montar botões.
     *
     * @param  array<int, array<string, mixed>>  $pendentes
     */
    private function texto(Conversa $motor, ?string $prefixo, array $pendentes): string
    {
        $partes = [$prefixo, $motor->respostaFinal()];

        if ($pendentes !== []) {
            $partes[] = $pendentes[0]['confirmacao'];
            // Sem isto a pessoa não tem como saber que a palavra é exigida exata.
            $partes[] = 'Responda apenas SIM para confirmar. Qualquer outra resposta cancela.';
        }

        // O cast antes do trim não é enfeite: $prefixo é null no caminho normal (sem
        // motivo de recusa), e trim(null) é deprecated no PHP 8 e erro no PHP 9.
        $partes = array_map(fn (?string $parte): string => trim((string) $parte), $partes);

        $texto = trim(implode("\n\n", array_filter($partes)));

        // Modelo que só chamou ferramenta e não escreveu nada deixaria o canal sem
        // resposta — silêncio parece falha para quem está do outro lado.
        return $texto !== ''
            ? $texto
            : 'Não consegui produzir uma resposta para esta mensagem. Tente reformular.';
    }

    private function motor(ConversaExterna $registro): Conversa
    {
        $motor = $registro->motor();

        if (! config('claudinho.api.acoes', true)) {
            $motor->semAcoes();
        }

        return $motor->comInstrucoes((string) config('claudinho.api.instrucoes', ''));
    }

    /**
     * Autentica como o usuário que a aplicação apontar. É o que faz gates e Global
     * Scopes valerem daqui para baixo, sem nenhum caminho paralelo de permissão.
     */
    private function usuario(string $canal, string $identificador): Authenticatable
    {
        $classe = (string) config('claudinho.api.resolvedor', '');

        if ($classe === '') {
            throw new RuntimeException(
                'Configure claudinho.api.resolvedor com uma classe que implemente '
                .ResolvedorDeUsuario::class.'.'
            );
        }

        $resolvedor = app($classe);

        if (! $resolvedor instanceof ResolvedorDeUsuario) {
            throw new RuntimeException($classe.' precisa implementar '.ResolvedorDeUsuario::class.'.');
        }

        $usuario = $resolvedor->resolver($canal, $identificador);

        // Mensagem genérica, e a mesma para número desconhecido e para usuário sem
        // permissão: quem manda a requisição não deve mapear quem está cadastrado
        // nem quem tem acesso, testando um por um.
        abort_if($usuario === null, 403, 'Remetente não autorizado.');

        // O MESMO gate que abre o chat em tela (claudinho.permissao). Sem isto, um
        // número cadastrado seria um caminho paralelo para usar o assistente sem a
        // permissão que a aplicação exige na tela — e caminho paralelo de permissão
        // é justamente o que este endpoint não pode ter.
        $permissao = config('claudinho.permissao');

        abort_if(filled($permissao) && ! $usuario->can($permissao), 403, 'Remetente não autorizado.');

        Auth::setUser($usuario);

        return $usuario;
    }
}
