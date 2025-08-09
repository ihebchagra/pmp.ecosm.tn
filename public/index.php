<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <title>Créateur PMP</title>
    <?php require_once __DIR__ . '/../powertrain/head.php' ?>
    <style>
        img {
            height: 10rem;
            margin-bottom: 1rem;
        }
        button {
            width: 100%;
        }
    </style>
</head>
<body>
    <main class="container home-container">
        <img src="img/fmt.png">
        <h1>Créateur PMP</h1>
        <h2>pmp.ecosm.tn</h2>
        <p>
            Bienvenue sur Créateur PMP, un outil en ligne pour créer des <b>Patient-management problems (PMP)</b> pour les étudiants en médecine.
        </p>
        <section>
            <button onclick="window.location.href='/login.php'">Se connecter avec votre email institutionnel (@fmt.utm.tn)</button>
        </section>
        <section>
            <button onclick="window.location.href='/about.php'" class="secondary">À Propos</button>
        </section>
    </main>
<?php
    /* <footer class="footer"> */
    /*     <p> <?php echo date('Y'); ?> Créateur PMP est un project open source publié sous la license GPL-3 - <a */
    /*             href="https://github.com/ihebchagra/createur-pmp" class="white-link">GitHub</a></p> */
    /* </footer> */
?>
</body>
</html>
