<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Project extends Model {protected $fillable=['name','location','budget_paisa','status'];protected $hidden=['user_id'];protected function casts():array{return ['budget_paisa'=>'integer'];}}
