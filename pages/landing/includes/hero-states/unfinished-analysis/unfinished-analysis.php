<!--
    unfinished-analysis.php — Self-contained feature "Незавершённый анализ".

    Подключает собственные assets самостоятельно:
      - unfinished-analysis.css (стили scoped внутри #unfinished-analysis-state)
      - unfinished-analysis.js  (определяет объект UnfinishedAnalysis с методом init())

    Показывается когда в сессии есть analysis_in_progress.
    Скрыт атрибутом hidden; показывается через HeroStatesManager.showUnfinishedAnalysis().
-->

<!-- Собственный CSS feature -->
<link rel="stylesheet" href="/pages/landing/includes/hero-states/unfinished-analysis/unfinished-analysis.css">

<section id="unfinished-analysis-state" hidden>

    <!-- Информация о незавершённом анализе -->
    <div class="unfinished-analysis__info">
        <p class="unfinished-analysis__text">
            Вы начали разбор
            <!--
                Тема анализа — динамически из сессии.
                htmlspecialchars() предотвращает XSS.
            -->
            <span class="unfinished-analysis__highlight" id="unfinished-analysis-topic">
                <?php echo htmlspecialchars($_SESSION['analysis_topic'] ?? 'на выбранную тему'); ?>
            </span>.
            Вы можете продолжить его с того места, где остановились.
        </p>
    </div>

    <!-- Кнопки действий -->
    <div class="unfinished-analysis__actions">

        <!-- Продолжить → continue-analysis.php → редирект в кабинет -->
        <button id="unfinished-analysis-continue" type="button" class="unfinished-analysis__btn">
            Продолжить разбор
        </button>

        <!-- Начать новый → reset-analysis.php → showDefaultHero() -->
        <button id="unfinished-analysis-reset" type="button" class="unfinished-analysis__btn unfinished-analysis__btn--secondary">
            Начать новый
        </button>

    </div>

</section>

<!-- Собственный JS feature — определяет объект UnfinishedAnalysis -->
<script src="/pages/landing/includes/hero-states/unfinished-analysis/unfinished-analysis.js"></script>
