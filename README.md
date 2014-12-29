peanut template engine
===========


###명령어

1. 반복문 `{{@value=data}} ... {{/@}}`

	@는 루프문의 시작을 나타내며,
	data가 리턴하는 배열의 요소수만큼 반복됩니다.
	`foreach($data as $key => $value) { ... }`로 변환.

	** template 파일 내부에서 생성한 value명이 php 파일에서 assgin 된 변수명과 중복되지 않도록 주의하세요.
	새로 지정된 값으로 치환됩니다.


2. 조건문 `{{?expression}} ... {{/?}}`

	?는 조건문의 시작을 나타내며,
	`if($expression) { ... }` 로 변환.


3. 조건문 `{{?expression}} ... {{:}} ... {{/?}}`

	:는 else구문을 나타내며,
	`if($expression) { ... } else { ... }` 로 변환.


4. 조건문 `{{?expression1}} ... {{:?expression2}} ... {{/?}}`

	:?는 else if 구문을 나타내며,
	`if($expression1) { ... } else if($expression2) { ... }` 로 변환.


5. 종결문 `{{/}}`

	/는 루프나 분기문의 끝을 나타냅니다.


6. 출력문 `{{=expression}}`

	=는 템플릿 변수 또는 표현식의 값을 출력하며 `echo $expression;` 로 변환.

	** `print_r`과 같이 함수 내에서 print하고 boolean값을 리턴하는 경우 "="를 생략해야함을 주의하세요.

7. 대입문 `{{value = 2)}}`
	`$value = 2;`로 변환

	** template 파일 내부에서 생성한 value명이 php 파일에서 assgin 된 변수명과 중복되지 않도록 주의하세요.
	새로 지정된 값으로 치환됩니다.
