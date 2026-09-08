<?php

// Rotas de módulos NÃO ficam aqui — cada módulo com endpoints HTTP registra as suas via
// Modules/<Nome>/routes/api.php + seu próprio RouteServiceProvider (nwidart/laravel-modules),
// que já aplica o grupo de middleware "api" e fecha o prefixo "api/v1" usado no projeto todo.
// Este arquivo só existe porque bootstrap/app.php referencia um `api:` para o health-check e
// para qualquer rota futura que seja de fato global (não pertença a nenhum módulo).
