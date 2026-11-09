<?php

return [
    'create' => 'Criar acesso à API',
    'create_help' => 'Escolhe os dados a que esta aplicação pode aceder. Um acesso à API nunca concede mais permissões do que a tua conta.',
    'unavailable' => 'Para criar um acesso, a API tem de estar ativa e a tua conta tem de permitir o início de sessão local. Podes continuar a revogar os acessos existentes.',
    'name' => 'Nome da aplicação',
    'lifetime' => 'Prazo de validade em minutos',
    'lifetime_help' => 'A definição do site limita o prazo de validade a um máximo de 30 dias.',
    'rights' => 'Dados e ações permitidos',
    'rights_help' => 'As permissões da conta e a disponibilidade das extensões são verificadas em cada pedido.',
    'password' => 'Palavra-passe atual',
    'code' => 'Código de autenticação de dois fatores',
    'created' => 'O teu acesso à API está pronto',
    'copy_once' => 'Copia este segredo agora e guarda-o na aplicação correspondente. Não voltará a ser apresentado. Qualquer pessoa que o possua pode utilizar as permissões selecionadas.',
    'secret' => 'Token Bearer',
    'back' => 'Voltar aos meus acessos à API',
    'scopes' => [
        'site_news' => ['label' => 'Ler as notícias públicas', 'description' => 'Consultar as notícias publicadas e o respetivo conteúdo público. Os rascunhos e conteúdos reservados ficam excluídos.'],
        'site_servers' => ['label' => 'Ler o estado público dos servidores', 'description' => 'Consultar a disponibilidade e o número de jogadores dos servidores públicos. Sem acesso a endereços internos, segredos ou comandos.'],
        'profile' => [
            'label' => 'Ler o meu perfil',
            'description' => 'Identificador, nome de utilizador, cargos e data de criação da tua conta.',
        ],
        'games' => [
            'label' => 'Ler as minhas identidades de jogo',
            'description' => 'Identificadores, nomes de utilizador e estado de verificação dos teus perfis de jogo.',
        ],
        'email' => [
            'label' => 'Ler o meu endereço de e-mail',
            'description' => 'O endereço de e-mail associado à tua conta.',
        ],
    ],
];
