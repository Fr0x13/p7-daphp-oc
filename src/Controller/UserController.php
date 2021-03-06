<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Paginator;
use App\Repository\UserRepository;
use App\Service\ViolationsChecker;
use FOS\RestBundle\Controller\ControllerTrait;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use Symfony\Component\Validator\ConstraintViolationList;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Security\Core\Security as SecurityFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use OpenApi\Annotations as OA;
use Nelmio\ApiDocBundle\Annotation\Model;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class UserController extends AbstractController
{
    use ControllerTrait;
    use ViolationsChecker;

    private $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @Rest\Get("/users", name="list_users")
     * @Rest\QueryParam(
     *  name="page",
     *  requirements="\d+",
     *  default="1",
     *  description="The asked page"
     * )
     * @Rest\View(
     *  StatusCode = 200,
     *  serializerGroups={"list"},
     *  serializerEnableMaxDepthChecks=true
     * )
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_SUPER_ADMIN')")
     * @OA\Response(
     *  response=200,
     *  description="Returns the paginated list of all users",
     *  @OA\JsonContent(
     *      type="array",
     *      @OA\Items(ref=@Model(type=User::class, groups={"list"}))
     *  )
     * )
     * @OA\Parameter(
     *     name="page",
     *     in="query",
     *     description="The page you want to load",
     *     @OA\Schema(type="integer")
     * )
     * @OA\Response(
     *  response=404,
     *  description="The page that you are looking for, does not exist!",
     * )
     * @OA\Response(
     *  response=401,
     *  description="Expired JWT Token | JWT Token not found | Invalid JWT Token",
     * )
     * @OA\Parameter(
     *  name="Authorization",
     *  in="header",
     *  required=true,
     *  description="Bearer Token"
     * )
     * @OA\Tag(name="users")
     */
    public function listUsers(ParamFetcherInterface $paramFetcher, SecurityFilter $security)
    {
        $paginator = new Paginator($this->userRepository);

        if (in_array("ROLE_SUPER_ADMIN", $security->getUser()->getRoles())) {
            return $paginator->getPage($paramFetcher->get('page'), true);
        }

        $loggedUser = $this->userRepository->findOneBy(["userName" => $security->getUser()->getUsername()]);

        $clientId = $loggedUser->getClient()->getId();

        return $paginator->getPage($paramFetcher->get('page'), true, ['client' => $clientId]);
    }

    /**
     * @Rest\Get(
     *  path = "/users/{id}",
     *  name = "show_user",
     *  requirements = {"id"="\d+"}
     * )
     * @Rest\View(
     *  StatusCode = 200,
     *  serializerGroups={"details_user"},
     *  serializerEnableMaxDepthChecks=true
     * )
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_SUPER_ADMIN')")
     * @OA\Response(
     *  response=200,
     *  description="Returns the chosen user",
     *  @Model(type=User::class, groups={"details_user"})
     * )
     * @OA\Parameter(
     *  name="id",
     *  in="path",
     *  description="ID of the user you want to see",
     *  @OA\Schema(type="integer")
     * )
     * @OA\Response(
     *  response=404,
     *  description="App\\Entity\\User object not found by the @ParamConverter annotation.",
     * )
     * @OA\Response(
     *  response=401,
     *  description="Expired JWT Token | JWT Token not found | Invalid JWT Token",
     * )
     * @OA\Response(
     *  response=403,
     *  description="Access denied.",
     * )
     * @OA\Parameter(
     *  name="Authorization",
     *  in="header",
     *  required=true,
     *  description="Bearer Token"
     * )
     * @OA\Tag(name="users")
     */
    public function showUser(User $user, SecurityFilter $security)
    {
        if (in_array("ROLE_SUPER_ADMIN", $security->getUser()->getRoles())) {
            return $user;
        }

        $loggedUser = $this->userRepository->findOneBy(["userName" => $security->getUser()->getUsername()]);

        if ($loggedUser->getClient()->getId() != $user->getClient()->getId()) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    /**
     * @Rest\Post("/users", name="create_user")
     * @ParamConverter(
     *  "user",
     *  converter="fos_rest.request_body",
     *  options={
     *      "validator"={ "groups"="Create" }
     *  }
     * )
     * @Rest\View(
     *  StatusCode = 201,
     *  serializerGroups={"details_user"},
     *  serializerEnableMaxDepthChecks=true
     * )
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_SUPER_ADMIN')")
     * @OA\Response(
     *  response=201,
     *  description="Returns created user",
     *  @Model(type=User::class, groups={"create_user"})
     * )
     * @OA\Parameter(
     *  name="User",
     *  in="query",
     *  @Model(type=User::class, groups={"create_user"}),
     *  required=true,
     *  description="The user object"
     * )
     * @OA\Response(
     *  response=400,
     *  description="The JSON sent contains invalid data. Here are the errors you need to correct: Field {property}: {message}"
     * )
     * @OA\Parameter(
     *  name="Authorization",
     *  in="header",
     *  required=true,
     *  description="Bearer Token"
     * )
     * @OA\Response(
     *  response=401,
     *  description="Expired JWT Token | JWT Token not found | Invalid JWT Token",
     * )
     * @OA\Response(
     *  response=403,
     *  description="Access denied.",
     * )
     * @OA\Tag(name="users")
     */
    public function createUser(User $user, ConstraintViolationList $violations, SecurityFilter $security, UserPasswordEncoderInterface $encoder)
    {
        $this->checkViolations($violations);

        if (!in_array("ROLE_SUPER_ADMIN", $security->getUser()->getRoles())) {
            $loggedUser = $this->userRepository->findOneBy(["userName" => $security->getUser()->getUsername()]);

            $user->setClient($loggedUser->getClient());
            $user->setRoles(["ROLE_USER"]);
        }

        if (empty($user->getRoles())) {
            $user->setRoles(["ROLE_USER"]);
        }

        $manager = $this->getDoctrine()->getManager();

        $user->setPassword($encoder->encodePassword($user, $user->getPassword()));
        $manager->persist($user);
        $manager->flush();

        return $user;
    }

    /**
     * @Rest\Put(
     *     path = "/users/{id}",
     *     name = "update_user",
     *     requirements = {"id"="\d+"}
     * )
     * @ParamConverter("newUser", converter="fos_rest.request_body")
     * @Rest\View(
     *  StatusCode = 200,
     *  serializerGroups={"details_user"},
     *  serializerEnableMaxDepthChecks=true
     * )
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_SUPER_ADMIN')")
     * @OA\Response(
     *  response=200,
     *  description="Returns modified user",
     *  @Model(type=User::class, groups={"details_user"})
     * )
     * @OA\Parameter(
     *  name="user",
     *  in="query",
     *  @Model(type=User::class, groups={"create_user"}),
     *  required=true,
     *  description="The client object"
     * )
     * @OA\Parameter(
     *  name="id",
     *  in="path",
     *  description="ID of the user you want to modify",
     *  @OA\Schema(type="integer")
     * )
     * @OA\Response(
     *  response=400,
     *  description="The JSON sent contains invalid data. Here are the errors you need to correct: Field {property}: {message}"
     * )
     * @OA\Parameter(
     *  name="Authorization",
     *  in="header",
     *  required=true,
     *  description="Bearer Token"
     * )
     * @OA\Response(
     *  response=401,
     *  description="Expired JWT Token | JWT Token not found | Invalid JWT Token",
     * )
     * @OA\Response(
     *  response=404,
     *  description="App\\Entity\\User object not found by the @ParamConverter annotation.",
     * )
     * @OA\Response(
     *  response=403,
     *  description="Access denied.",
     * )
     * @OA\Tag(name="users")
     */
    public function updateUser(User $user, User $newUser, ConstraintViolationList $violations, SecurityFilter $security, UserPasswordEncoderInterface $encoder)
    {
        $this->checkViolations($violations);

        if (!in_array("ROLE_SUPER_ADMIN", $security->getUser()->getRoles())) {
            $loggedUser = $this->userRepository->findOneBy(["userName" => $security->getUser()->getUsername()]);

            if ($loggedUser->getClient()->getId() != $user->getClient()->getId()) {
                throw new AccessDeniedException("You don't have the rights for modifying this user.");
            }

            $user->setRoles(["ROLE_USER"]);
        } else {
            if ($newUser->getRoles()) {
                $user->setRoles($newUser->getRoles());
            }
        }

        if ($newUser->getUserName()) {
            $user->setUserName($newUser->getUserName());
        }

        if ($newUser->getPassword()) {
            $user->setPassword($encoder->encodePassword($user, $newUser->getPassword()));
        }

        if ($newUser->getEmail()) {
            $user->setEmail($newUser->getEmail());
        }

        if ($newUser->getPhoneNumber()) {
            $user->setPhoneNumber($newUser->getPhoneNumber());
        }

        if ($newUser->getClient()) {
            $user->setClient($newUser->getClient());
        }

        $this->getDoctrine()->getManager()->flush();

        return $user;
    }

    /**
     * @Rest\Delete(
     *  path = "/users/{id}",
     *  name = "delete_user",
     *  requirements = {"id"="\d+"}
     * )
     * @Rest\View(StatusCode = 204)
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_SUPER_ADMIN')")
     * @OA\Response(
     *  response=204,
     *  description="Returns an empty object",
     *  @Model(type=User::class, groups={"deleted"})
     * )
     * @OA\Parameter(
     *  name="id",
     *  in="path",
     *  description="ID of the user you want to delete",
     *  @OA\Schema(type="integer")
     * )
     * @OA\Response(
     *  response=404,
     *  description="App\\Entity\\User object not found by the @ParamConverter annotation.",
     * )
     * @OA\Response(
     *  response=401,
     *  description="Expired JWT Token | JWT Token not found | Invalid JWT Token",
     * )
     * @OA\Parameter(
     *  name="Authorization",
     *  in="header",
     *  required=true,
     *  description="Bearer Token"
     * )
     * @OA\Response(
     *  response=403,
     *  description="You don't have the rights for deleting this user.",
     * )
     * @OA\Tag(name="users")
     */
    public function deleteUser(User $user, SecurityFilter $security)
    {
        if (!in_array("ROLE_SUPER_ADMIN", $security->getUser()->getRoles())) {
            $loggedUser = $this->userRepository->findOneBy(["userName" => $security->getUser()->getUsername()]);

            if ($loggedUser->getClient()->getId() != $user->getClient()->getId()) {
                throw new AccessDeniedException("You don't have the rights for deleting this user.");
            }
        }

        $manager = $this->getDoctrine()->getManager();

        $manager->remove($user);
        $manager->flush();

        return;
    }
}
