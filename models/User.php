<?php

namespace app\models;

use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * Пользователь SmartMatch и Yii Identity.
 *
 * Модель хранит учётные данные, статус доступа и выбранную компанию. Через
 * `company_id` определяется tenant-контекст для контроллеров и выборок данных.
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property int $status
 * @property string $password_hash
 * @property string|null $password_reset_token
 * @property string $auth_key
 * @property int|null $company_id
 * @property int $created_at
 * @property int $updated_at
 *
 * @property Company|null $company
 */
class User extends ActiveRecord implements IdentityInterface
{
    const STATUS_DELETED = 0;
    const STATUS_ACTIVE = 10;

    /**
     * Возвращает имя таблицы пользователей.
     *
     * @return string Имя таблицы `user` с учётом префикса Yii.
     */
    public static function tableName()
    {
        return '{{%user}}';
    }

    /**
     * Подключает автоматическое заполнение timestamp-полей.
     *
     * @return array Конфигурация Yii behaviors.
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::className(),
        ];
    }

    /**
     * Описывает правила валидации пользователя.
     *
     * Проверяет уникальность логина и email, активный статус и существование
     * выбранной компании, если она задана.
     *
     * @return array Правила Yii Validator.
     */
    public function rules()
    {
        return [
            ['status', 'default', 'value' => self::STATUS_ACTIVE],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DELETED]],
            ['username', 'required'],
            ['username', 'unique'],
            ['username', 'string', 'min' => 2, 'max' => 255],
            ['email', 'required'],
            ['email', 'email'],
            ['email', 'unique'],
            ['password_hash', 'required'],
            ['company_id', 'integer'],
            ['company_id', 'exist', 'skipOnError' => true, 'targetClass' => Company::className(), 'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    /**
     * Возвращает подписи атрибутов пользователя.
     *
     * @return array Массив `attribute => label`.
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'username' => 'Имя пользователя',
            'email' => 'Email',
            'status' => 'Статус',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
            'company_id' => 'Компания',
        ];
    }

    /**
     * Возвращает компанию, выбранную пользователем.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Company`.
     */
    public function getCompany()
    {
        return $this->hasOne(Company::className(), ['id' => 'company_id']);
    }

    /**
     * Проверяет наличие выбранной компании у пользователя.
     *
     * @return bool `true`, если `company_id` заполнен.
     */
    public function hasCompany()
    {
        return !empty($this->company_id);
    }

    /**
     * Устанавливает текущую компанию пользователя.
     *
     * Метод сохраняет только поле `company_id` и используется при выборе
     * компании после входа.
     *
     * @param int|null $companyId ID компании или `null` для сброса.
     * @return bool Успешность сохранения.
     */
    public function setCompany($companyId)
    {
        $this->company_id = $companyId;
        return $this->save(false, ['company_id']);
    }

    /**
     * Находит активного пользователя по первичному ключу для Yii auth.
     *
     * @param int|string $id ID пользователя.
     * @return self|null Активный пользователь или `null`.
     */
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * Поиск пользователя по access token не поддерживается.
     *
     * @param mixed $token Access token.
     * @param mixed|null $type Тип токена.
     * @return never
     * @throws NotSupportedException Метод не реализован в приложении.
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    /**
     * Находит активного пользователя по логину.
     *
     * @param string $username Логин пользователя.
     * @return self|null Пользователь или `null`.
     */
    public static function findByUsername($username)
    {
        return static::findOne(['username' => $username, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * Находит пользователя по действующему токену сброса пароля.
     *
     * @param string $token Токен формата `random_timestamp`.
     * @return self|null Пользователь или `null`, если токен пустой/просрочен.
     */
    public static function findByPasswordResetToken($token)
    {
        if (!static::isPasswordResetTokenValid($token)) {
            return null;
        }

        return static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Проверяет срок действия токена сброса пароля.
     *
     * @param string|null $token Токен формата `random_timestamp`.
     * @return bool `true`, если токен не пустой и не истёк.
     */
    public static function isPasswordResetTokenValid($token)
    {
        if (empty($token)) {
            return false;
        }

        $timestamp = (int) substr($token, strrpos($token, '_') + 1);
        $expire = Yii::$app->params['user.passwordResetTokenExpire'];
        return $timestamp + $expire >= time();
    }

    /**
     * Возвращает идентификатор пользователя для Yii Identity.
     *
     * @return int|string|null Первичный ключ пользователя.
     */
    public function getId()
    {
        return $this->getPrimaryKey();
    }

    /**
     * Возвращает ключ авторизации Yii.
     *
     * @return string|null Auth key пользователя.
     */
    public function getAuthKey()
    {
        return $this->auth_key;
    }

    /**
     * Проверяет auth key из cookie/сессии.
     *
     * @param string $authKey Проверяемый ключ.
     * @return bool `true`, если ключ совпадает с сохранённым.
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Проверяет пароль пользователя по сохранённому хэшу.
     *
     * @param string $password Пароль в открытом виде.
     * @return bool Успешность проверки пароля.
     */
    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * Генерирует и сохраняет хэш нового пароля в модели.
     *
     * @param string $password Пароль в открытом виде.
     * @return void
     */
    public function setPassword($password)
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    /**
     * Генерирует новый auth key для пользователя.
     *
     * @return void
     */
    public function generateAuthKey()
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /**
     * Генерирует токен сброса пароля с timestamp-суффиксом.
     *
     * @return void
     */
    public function generatePasswordResetToken()
    {
        $this->password_reset_token = Yii::$app->security->generateRandomString() . '_' . time();
    }

    /**
     * Очищает токен сброса пароля.
     *
     * @return void
     */
    public function removePasswordResetToken()
    {
        $this->password_reset_token = null;
    }
}
