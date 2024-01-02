<?php

class Router
{
    private $routes = [];

    public function get($path, $callback)
    {
        $this->addRoute('GET', $path, $callback);
    }

    public function post($path, $callback)
    {
        $this->addRoute('POST', $path, $callback);
    }

    private function addRoute($method, $path, $callback)
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'callback' => $callback,
        ];
    }

    public function resolve()
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->isPathMatch($route['path'], $path)) {
                $params = $this->extractParams($route['path'], $path);
                $response = call_user_func($route['callback'], $params);

                // Se a resposta for um array, converte para JSON e envia a resposta
                if (is_array($response)) {
                    header('Content-Type: application/json');
                    echo json_encode($response);
                }

                return;
            }
        }

        http_response_code(404);
        echo json_encode(['status' => 404, 'message' => 'Not Found']);
        exit;
    }

    private function isPathMatch($pattern, $path)
    {
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '/^' . $pattern . '$/';
        return (bool) preg_match($pattern, $path);
    }

    private function extractParams($pattern, $path)
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        $params = [];
        foreach ($patternParts as $index => $part) {
            if (isset($pathParts[$index]) && !empty($part) && $part[0] === '{' && substr($part, -1) === '}') {
                $params[] = $pathParts[$index];
            }
        }

        return $params;
    }
}

$databaseConfig = [
    'host' => 'localhost',
    'user' => 'mateus',
    'password' => 'Mm@#91284025',
    'database' => 'articlesTable',
];

try {
    $db = new PDO(
        "mysql:host={$databaseConfig['host']};dbname={$databaseConfig['database']}",
        $databaseConfig['user'],
        $databaseConfig['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Erro no banco de dados: " . $e->getMessage());
    echo json_encode(['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    error_log("Erro: " . $e->getMessage());
    echo json_encode(['status' => 500, 'message' => 'Server Error: ' . $e->getMessage()]);
    exit;
}

$router = new Router();

$router->get('/get', function ($params) use ($db) {
    try {
        if (!$db) {
            throw new PDOException("Falha na conexão com o banco de dados.");
        }

        $query = $db->query("SELECT * FROM articles");

        if ($query === false) {
            throw new PDOException("Erro na execução da consulta SQL.");
        }

        $result = $query->fetchAll(PDO::FETCH_ASSOC);

        return ['status' => 200, 'data' => $result];
    } catch (PDOException $e) {
        return ['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()];
    }
});

$router->get('/find/{id}', function ($params) use ($db) {
    try {
        $id = end($params);

        if ($id !== null) {
            $query = $db->prepare("SELECT * FROM articles WHERE id = ?");
            $query->execute([$id]);
            $result = $query->fetch(PDO::FETCH_ASSOC);

            if ($result !== false) {
                return ['status' => 200, 'data' => $result];
            } else {
                return ['status' => 404, 'message' => 'Registro não encontrado'];
            }
        } else {
            return ['status' => 400, 'message' => 'Parâmetro {id} ausente ou inválido na URL'];
        }
    } catch (PDOException $e) {
        return ['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()];
    }
});

$router->post('/create', function () use ($db) {
    try {
        $data = $_POST;

        $requiredFields = ['name', 'article_body', 'author', 'author_avatar'];
        if (array_diff($requiredFields, array_keys($data)) === []) {
            if (array_filter($data) === $data) {
                $insertQuery = $db->prepare("INSERT INTO articles (name, article_body, author, author_avatar) VALUES (?, ?, ?, ?)");
                $insertQuery->execute([$data['name'], $data['article_body'], $data['author'], $data['author_avatar']]);

                return ['status' => 201, 'message' => 'Registro criado com sucesso'];
            } else {
                return ['status' => 400, 'message' => 'Os valores não podem estar vazios'];
            }
        } else {
            return ['status' => 400, 'message' => 'Parâmetros inválidos'];
        }
    } catch (PDOException $e) {
        return ['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()];
    }
});

$router->post('/update/{id}', function ($params) use ($db) {
    try {
        $id = end($params);
        $data = json_decode(file_get_contents('php://input'), true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erro na decodificação JSON: ' . json_last_error_msg());
        }

        $requiredFields = ['name', 'article_body', 'author', 'author_avatar'];
        if (array_diff($requiredFields, array_keys($data)) === []) {
            $checkExistenceQuery = $db->prepare("SELECT id FROM articles WHERE id = ?");
            $checkExistenceQuery->execute([$id]);
            $existingRecord = $checkExistenceQuery->fetch(PDO::FETCH_ASSOC);

            if ($existingRecord) {
                $updateQuery = $db->prepare("UPDATE articles SET name = ?, article_body = ?, author = ?, author_avatar = ? WHERE id = ?");
                $updateQuery->execute([$data['name'], $data['article_body'], $data['author'], $data['author_avatar'], $id]);

                return ['status' => 200, 'message' => 'Registro atualizado com sucesso'];
            } else {
                return ['status' => 404, 'message' => 'Registro não encontrado'];
            }
        } else {
            return ['status' => 400, 'message' => 'Parâmetros inválidos'];
        }
    } catch (PDOException $e) {
        return ['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()];
    } catch (Exception $e) {
        return ['status' => 400, 'message' => 'Erro na solicitação: ' . $e->getMessage()];
    }
});

$router->post('/delete/{id}', function ($params) use ($db) {
    try {
        $id = end($params);
        $checkExistenceQuery = $db->prepare("SELECT id FROM articles WHERE id = ?");
        $checkExistenceQuery->execute([$id]);
        $existingRecord = $checkExistenceQuery->fetch(PDO::FETCH_ASSOC);

        if ($existingRecord) {
            $deleteQuery = $db->prepare("DELETE FROM articles WHERE id = ?");
            $deleteQuery->execute([$id]);

            return ['status' => 200, 'message' => 'Registro excluído com sucesso'];
        } else {
            return ['status' => 404, 'message' => 'Registro não encontrado'];
        }
    } catch (PDOException $e) {
        return ['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()];
    }
});

try {
    $router->resolve();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
}


// Manipulação da requisição
$router->resolve();
