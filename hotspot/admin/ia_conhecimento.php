<?php
// Informacoes que a IA pode usar para responder os clientes. EDITE ESTE ARQUIVO e faca o deploy.
// A IA so responde com o que estiver aqui ou com o que o SGP devolver; o resto ela encaminha ao atendimento.
// Nao coloque senhas, tokens ou dados pessoais de clientes aqui.
return <<<'TXT'
EMPRESA
- ServiceNet, provedor de internet. Site: servicenetpro.com.br

PLANOS E VALORES
(preencher: nome do plano, velocidade e valor mensal de cada um)

COBERTURA E INSTALACAO
(preencher: bairros/cidades atendidos e como contratar)

PAGAMENTO
(preencher: formas de pagamento, chave Pix, data de vencimento, como funciona a segunda via)
- Quem pagar por Pix ou transferencia pode enviar o comprovante neste mesmo WhatsApp; a equipe da baixa no pagamento.

HORARIO DE ATENDIMENTO HUMANO
(preencher: dias e horarios)

SUPORTE TECNICO BASICO
(preencher: orientacoes simples, por exemplo reiniciar o roteador por 30 segundos)
TXT;
