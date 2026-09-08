<?php

// Agregador de rotas dos módulos — mantido aqui (em vez de nos Service Providers de cada
// módulo) para herdar o grupo de middleware "api" e o apiPrefix ("api/v1") configurados em
// bootstrap/app.php (withRouting). Cada módulo com endpoints HTTP tem seu próprio
// Presentation/routes.php; módulos ainda sem endpoints (Clients, Equipments, Orders — CRUD é
// fora desta sessão) ainda não aparecem aqui.

require base_path('app/Modules/Identity/Presentation/routes.php');
