<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use BWC\Visa\Matcher;
use BWC\Visa\MrzVerifier;

$pivot = 26;
$good = make_td3('TESTER', 'ANNA MARIE', 'AB1234567', 'CHE', '850312', 'F', '320630');

echo "MrzVerifier (fail-closed gate)\n";
$r = MrzVerifier::apply(llm_record($good), $pivot);
t_eq('A1 valid MRZ -> no review', $r['review'], []);
t_eq('A1 check digits all true', [$r['check_digits']['document'], $r['check_digits']['dob'], $r['check_digits']['expiry'], $r['check_digits']['composite']], [true, true, true, true]);

$bad = $good; $bad[1] = substr_replace($bad[1], '3', 18, 1); // dob digit changed (850313 vs check)
$r = MrzVerifier::apply(llm_record($bad), $pivot);
t_eq('A2 DOB digit altered -> review', in_array('check digit failed or missing: date of birth', $r['review'], true), true);

$bad = $good; $bad[1] = substr_replace($bad[1], '9', 27, 1); // expiry check digit wrong
$r = MrzVerifier::apply(llm_record($bad), $pivot);
t_eq('A3 expiry check digit wrong -> review', in_array('check digit failed or missing: expiry date', $r['review'], true), true);

$bad = $good; $bad[1] = substr($bad[1], 0, 43) . ((int) $bad[1][43] === 9 ? '0' : (string) ((int) $bad[1][43] + 1));
$r = MrzVerifier::apply(llm_record($bad), $pivot);
t_eq('A4 only composite wrong -> warning, no review', $r['review'], []);
t_eq('A4 composite flagged false', $r['check_digits']['composite'], false);

$long = $good; $long[1] .= '<'; // 45 chars (LLM added a filler)
$r = MrzVerifier::apply(llm_record($long), $pivot);
t_eq('A7 45-char data line -> still verified, no review', $r['review'], []);
t_eq('A7 line format flagged invalid (warning only)', $r['mrz_format']['valid'], false);

$r = MrzVerifier::apply(llm_record([]), $pivot);
t_eq('no raw MRZ lines -> review', count($r['review']) > 0, true);

$r = MrzVerifier::apply(llm_record($good, ['date_of_birth' => '2085-03-12', 'expiry_date' => '1932-06-30']), $pivot);
t_eq('century error in LLM date is corrected from MRZ', [$r['mrz']['date_of_birth'], $r['mrz']['expiry_date'], $r['review']], ['1985-03-12', '2032-06-30', []]);

$r = MrzVerifier::apply(llm_record($good, ['document_number' => 'AB123456O']), $pivot);
t_eq('LLM passport number with O/0 error is replaced by verified MRZ value', $r['mrz']['document_number'], 'AB1234567');

$r = MrzVerifier::apply(llm_record($good, ['surname' => 'SCHMIDT']), $pivot);
t_eq('surname disagrees between reads -> review', count($r['review']) > 0, true);

$r = MrzVerifier::apply(llm_record($good, ['sex' => 'M']), $pivot);
t_eq('sex disagrees between reads -> review', count($r['review']) > 0, true);

echo "\nMrzCheck::repairDocNumber (regression: all-digit candidates)\n";
$repaired = null;
$ok = true;
try {
    foreach (['B12345679', 'O12345678', 'I23456789', 'S12345678'] as $field) {
        $repaired = \BWC\Visa\MrzCheck::repairDocNumber($field, '1');
        $ok = $ok && ($repaired === null || is_string($repaired));
    }
} catch (\TypeError $e) {
    $ok = false;
}
t_eq('repair never throws and always returns ?string', $ok, true);

echo "\nMatcher status\n";
$app = ['no' => '1', 'full_name' => 'Tester Anna Marie', 'gender' => 'F', 'nationality' => 'CHE', 'date_of_birth' => '1985-03-12',
    'place_of_birth' => 'Utopolis', 'passport_number' => 'AB1234567', 'passport_expiry_date' => '2032-06-30',
    'date_of_arrival' => '2026-11-11', 'date_of_departure' => '2026-11-15'];
$m = new Matcher(true, 6, '2026-10-05');
$ok = MrzVerifier::apply(llm_record($good), $pivot);
t_eq('verified read + matching Excel -> success', $m->match([$app], [$ok])[0]['status'], 'success');

$rev = MrzVerifier::apply(llm_record($long, ['surname' => 'TESTER']), $pivot);
$rev['review'] = ['simulated unverifiable read'];
$res = $m->match([$app], [$rev])[0];
t_eq('unverifiable read + matching Excel -> review (not success)', $res['status'], 'review');

$app2 = $app; $app2['date_of_birth'] = '1985-03-13';
t_eq('Excel mismatch -> failed', $m->match([$app2], [$ok])[0]['status'], 'failed');

t_done();
