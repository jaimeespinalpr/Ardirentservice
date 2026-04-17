<?php
declare(strict_types=1);

function clean_text(?string $value): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);

    return preg_replace('/\s+/', ' ', $value) ?? '';
}

function clean_multiline(?string $value): string
{
    $value = (string) $value;
    $value = strip_tags($value);
    $value = preg_replace("/\r\n|\r/", "\n", $value) ?? '';

    return trim($value);
}

function clean_header(?string $value): string
{
    $value = (string) $value;
    return str_replace(["\r", "\n"], '', trim($value));
}

function redirect_to(string $path, int $status = 303): never
{
    header("Location: {$path}", true, $status);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_to('/#production');
}

$honeypot = clean_text($_POST['company'] ?? '');
if ($honeypot !== '') {
    redirect_to('/?sent=1#production');
}

$lang = clean_text($_POST['lang'] ?? 'en');
$lang = $lang === 'es' ? 'es' : 'en';

$name = clean_text($_POST['name'] ?? '');
$emailRaw = clean_text($_POST['email'] ?? '');
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$phone = clean_text($_POST['phone'] ?? '');
$projectType = clean_text($_POST['project_type'] ?? '');
$timeOfDay = clean_text($_POST['time_of_day'] ?? '');
$noiseLevel = clean_text($_POST['noise_level'] ?? '');
$equipmentNeed = clean_text($_POST['equipment_need'] ?? '');
$shootDate = clean_text($_POST['shoot_date'] ?? '');
$location = clean_text($_POST['location'] ?? '');
$notes = clean_multiline($_POST['project_notes'] ?? '');

$allowedProjectTypes = ['photo', 'video', 'podcast', 'livestream', 'event', 'other'];
$allowedTimeOfDay = ['day', 'night', 'both'];
$allowedNoiseLevel = ['quiet', 'moderate', 'loud', 'unsure'];
$allowedEquipmentNeed = ['guidance', 'one-camera', 'camera-lenses', 'full-kit', 'unsure'];

if ($name === '' || $email === '' || $projectType === '' || $timeOfDay === '' || $noiseLevel === '' || $equipmentNeed === '' || $notes === '') {
    redirect_to('/?error=1#production');
}

if (
    !in_array($projectType, $allowedProjectTypes, true) ||
    !in_array($timeOfDay, $allowedTimeOfDay, true) ||
    !in_array($noiseLevel, $allowedNoiseLevel, true) ||
    !in_array($equipmentNeed, $allowedEquipmentNeed, true)
) {
    redirect_to('/?error=1#production');
}

$labels = [
    'en' => [
        'subject' => 'New project brief - Ardi Rent & Service',
        'heading' => 'New project brief received',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'WhatsApp / Phone',
        'projectType' => 'Project type',
        'timeOfDay' => 'Day or night',
        'noiseLevel' => 'Noise level',
        'equipmentNeed' => 'Estimated gear need',
        'shootDate' => 'Shoot date',
        'location' => 'Location',
        'notes' => 'Project notes',
        'projectTypes' => [
            'photo' => 'Photo shoot',
            'video' => 'Video production',
            'podcast' => 'Podcast',
            'livestream' => 'Livestream',
            'event' => 'Event coverage',
            'other' => 'Other',
        ],
        'times' => [
            'day' => 'Day',
            'night' => 'Night',
            'both' => 'Both / not sure',
        ],
        'noise' => [
            'quiet' => 'Quiet',
            'moderate' => 'Moderate',
            'loud' => 'Loud',
            'unsure' => 'Not sure',
        ],
        'gear' => [
            'guidance' => 'Just guidance',
            'one-camera' => 'One camera',
            'camera-lenses' => 'Camera + lenses',
            'full-kit' => 'Full production kit',
            'unsure' => 'Not sure',
        ],
    ],
    'es' => [
        'subject' => 'Nuevo brief de proyecto - Ardi Rent & Service',
        'heading' => 'Nuevo brief de proyecto recibido',
        'name' => 'Nombre',
        'email' => 'Correo',
        'phone' => 'WhatsApp / Teléfono',
        'projectType' => 'Tipo de proyecto',
        'timeOfDay' => 'Día o noche',
        'noiseLevel' => 'Nivel de ruido',
        'equipmentNeed' => 'Equipo estimado',
        'shootDate' => 'Fecha de la sesión',
        'location' => 'Lugar',
        'notes' => 'Notas del proyecto',
        'projectTypes' => [
            'photo' => 'Sesión de fotos',
            'video' => 'Producción de video',
            'podcast' => 'Podcast',
            'livestream' => 'Livestream',
            'event' => 'Cobertura de evento',
            'other' => 'Otro',
        ],
        'times' => [
            'day' => 'Día',
            'night' => 'Noche',
            'both' => 'Ambos / no estoy seguro',
        ],
        'noise' => [
            'quiet' => 'Silencioso',
            'moderate' => 'Moderado',
            'loud' => 'Fuerte',
            'unsure' => 'No estoy seguro',
        ],
        'gear' => [
            'guidance' => 'Solo guía',
            'one-camera' => 'Una cámara',
            'camera-lenses' => 'Cámara + lentes',
            'full-kit' => 'Equipo de producción completo',
            'unsure' => 'No estoy seguro',
        ],
    ],
];

$copy = $labels[$lang];
$safeReplyTo = clean_header($email);
$subject = clean_header($copy['subject']);
$fromAddress = 'no-reply@ardirentservice.com';
$fromName = 'Ardi Rent & Service';

$body = [
    $copy['heading'],
    '',
    "{$copy['name']}: {$name}",
    "{$copy['email']}: {$email}",
    "{$copy['phone']}: " . ($phone !== '' ? $phone : '-'),
    "{$copy['projectType']}: " . ($copy['projectTypes'][$projectType] ?? $projectType),
    "{$copy['timeOfDay']}: " . ($copy['times'][$timeOfDay] ?? $timeOfDay),
    "{$copy['noiseLevel']}: " . ($copy['noise'][$noiseLevel] ?? $noiseLevel),
    "{$copy['equipmentNeed']}: " . ($copy['gear'][$equipmentNeed] ?? $equipmentNeed),
    "{$copy['shootDate']}: " . ($shootDate !== '' ? $shootDate : '-'),
    "{$copy['location']}: " . ($location !== '' ? $location : '-'),
    '',
    "{$copy['notes']}:",
    $notes,
];

$headers = [
    "From: {$fromName} <{$fromAddress}>",
    "Reply-To: {$safeReplyTo}",
    'Content-Type: text/plain; charset=UTF-8',
    'MIME-Version: 1.0',
    'X-Mailer: PHP/' . PHP_VERSION,
];

$sent = mail('ardirentservice@gmail.com', $subject, implode("\n", $body), implode("\r\n", $headers));

if ($sent) {
    redirect_to('/?sent=1#production');
}

redirect_to('/?error=1#production');
