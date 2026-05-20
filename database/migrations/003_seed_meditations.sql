-- ─── 003_seed_meditations.sql ─────────────────────────────────────────────────
-- 5 общих категорий + 2-3 медитации в каждой.
-- is_free_first_month = 1 → доступна всем без покупки (2 шт. на каталог).
-- is_free_first_month = 0 → только после покупки.
-- generation_status = 'ready', type = 'general', user_id = NULL.
-- ──────────────────────────────────────────────────────────────────────────────

-- ── Категории ─────────────────────────────────────────────────────────────────

INSERT INTO meditation_categories (user_id, name, slug, description, sort_order) VALUES
(NULL, 'Уверенность',            'confidence',    'Медитации для укрепления веры в себя и своих силах',                     1),
(NULL, 'Отношения в семье',      'family',        'Медитации для гармонии и понимания в семейных отношениях',               2),
(NULL, 'Деньги',                 'money',         'Медитации для здорового отношения к деньгам и изобилию',                 3),
(NULL, 'Здоровье',               'health',        'Медитации для поддержки тела и восстановления жизненных сил',            4),
(NULL, 'Отношения с детьми',     'children',      'Медитации для глубокого контакта и понимания с детьми',                  5);

-- ── Медитации: Уверенность ────────────────────────────────────────────────────

INSERT INTO meditations (category_id, user_id, type, topic_type, topic, title, description, price, is_free_first_month, generation_status) VALUES
(
    (SELECT id FROM meditation_categories WHERE slug = 'confidence'),
    NULL, 'general', 'general',
    'Уверенность',
    'Я достаточен',
    'Медитация помогает принять себя таким, какой ты есть, отпустить сомнения и ощутить внутреннюю опору.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'confidence'),
    NULL, 'general', 'general',
    'Уверенность',
    'Голос внутри',
    'Практика нахождения собственного голоса — того, что ты думаешь и чувствуешь на самом деле.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'confidence'),
    NULL, 'general', 'general',
    'Уверенность',
    'Сила решения',
    'Медитация для тех, кто хочет принимать решения из состояния ясности, а не страха.',
    390.00, 0, 'ready'
);

-- ── Медитации: Отношения в семье ──────────────────────────────────────────────

INSERT INTO meditations (category_id, user_id, type, topic_type, topic, title, description, price, is_free_first_month, generation_status) VALUES
(
    (SELECT id FROM meditation_categories WHERE slug = 'family'),
    NULL, 'general', 'general',
    'Отношения в семье',
    'Мягкое сердце',
    'Медитация для снятия напряжения в отношениях с близкими и открытия сердца навстречу.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'family'),
    NULL, 'general', 'general',
    'Отношения в семье',
    'Я слышу тебя',
    'Практика глубокого присутствия и слышания партнёра или родителей без защит и оценок.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'family'),
    NULL, 'general', 'general',
    'Отношения в семье',
    'Границы с любовью',
    'Медитация для тех, кто хочет сохранять себя в семейных отношениях, не разрушая близость.',
    390.00, 0, 'ready'
);

-- ── Медитации: Деньги ─────────────────────────────────────────────────────────

INSERT INTO meditations (category_id, user_id, type, topic_type, topic, title, description, price, is_free_first_month, generation_status) VALUES
(
    (SELECT id FROM meditation_categories WHERE slug = 'money'),
    NULL, 'general', 'general',
    'Деньги',
    'Я заслуживаю',
    'Медитация для работы с убеждением «деньги не для меня» и ощущения права получать.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'money'),
    NULL, 'general', 'general',
    'Деньги',
    'Изобилие вокруг меня',
    'Практика для перенастройки внимания с нехватки на то, что уже есть и приходит.',
    390.00, 0, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'money'),
    NULL, 'general', 'general',
    'Деньги',
    'Страх потери',
    'Медитация для тех, кто держит деньги из страха, а не из выбора — отпустить тревогу о будущем.',
    390.00, 0, 'ready'
);

-- ── Медитации: Здоровье ───────────────────────────────────────────────────────

INSERT INTO meditations (category_id, user_id, type, topic_type, topic, title, description, price, is_free_first_month, generation_status) VALUES
(
    (SELECT id FROM meditation_categories WHERE slug = 'health'),
    NULL, 'general', 'general',
    'Здоровье',
    'Тело знает',
    'Медитация для восстановления контакта с телом и слышания его сигналов.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'health'),
    NULL, 'general', 'general',
    'Здоровье',
    'Глубокий отдых',
    'Практика полного расслабления и восстановления — для тех, кто давно не отдыхал по-настоящему.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'health'),
    NULL, 'general', 'general',
    'Здоровье',
    'Исцеление изнутри',
    'Медитация для работы с хроническим напряжением и поддержки природных процессов восстановления.',
    390.00, 0, 'ready'
);

-- ── Медитации: Отношения с детьми ─────────────────────────────────────────────

INSERT INTO meditations (category_id, user_id, type, topic_type, topic, title, description, price, is_free_first_month, generation_status) VALUES
(
    (SELECT id FROM meditation_categories WHERE slug = 'children'),
    NULL, 'general', 'general',
    'Отношения с детьми',
    'Я рядом',
    'Медитация для возвращения к ребёнку из состояния присутствия, а не усталости или тревоги.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'children'),
    NULL, 'general', 'general',
    'Отношения с детьми',
    'Принять, не исправить',
    'Практика для родителей, которые хотят поддерживать ребёнка, не пытаясь его переделать.',
    290.00, 1, 'ready'
),
(
    (SELECT id FROM meditation_categories WHERE slug = 'children'),
    NULL, 'general', 'general',
    'Отношения с детьми',
    'Мой внутренний ребёнок',
    'Медитация для исцеления отношений с детьми через контакт с собственным детским опытом.',
    390.00, 0, 'ready'
);
