<?php

return [
    'title' => 'Conservação de dados',
    'description' => 'Escolha durante quanto tempo manter as versões antigas dos seus conteúdos.',
    'saved' => 'As definições de limpeza foram guardadas.',
    'command_description' => 'Limpar versões antigas e dados temporários de acordo com a política de conservação configurada',
    'schedule_help' => 'Estes períodos aplicam-se na próxima limpeza. Com as tarefas automáticas ativas, a manutenção é executada a cada hora. Também pode executar uma limpeza manual.',
    'history_title' => 'Histórico dos conteúdos',
    'other_cleanups' => 'Outras limpezas',
    'fields' => [
        'history' => [
            'label' => 'Duração do histórico, em dias',
            'help' => 'As versões mais antigas são eliminadas automaticamente.',
        ],
        'temporary' => [
            'label' => 'Ficheiros não utilizados, em dias',
            'help' => 'Imagens removidas dos seus conteúdos e do respetivo histórico, acessos de convidados expirados.',
        ],
        'technical' => [
            'label' => 'Envios e tarefas antigos, em dias',
            'help' => 'Envios de notificações antigos e tarefas concluídas. As tarefas que necessitam de nova tentativa são mantidas.',
        ],
    ],
    'history_limit' => 'Número de versões a manter por conteúdo',
    'history_limit_help' => 'Quando este limite é excedido, as versões mais antigas são eliminadas.',
    'preserved_help' => 'Os seus rascunhos, conteúdos atuais e imagens ainda utilizadas são mantidos.',
    'audit_link' => 'Definir a conservação do registo de atividade',
];
